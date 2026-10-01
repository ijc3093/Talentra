<?php
declare(strict_types=1);
require_once __DIR__.'/includes/session_user.php'; requireUserLogin();
require_once __DIR__.'/controller.php'; require_once __DIR__.'/includes/theme_prefs.php'; require_once __DIR__.'/includes/post_action_thin_icons.php';
if(!function_exists('h')){function h(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}}
$dbh=(new Controller())->pdo();$meId=(int)($_SESSION['user_id']??0);$communityId=(int)($_GET['id']??$_POST['community_id']??0);
if(function_exists('sendNoCacheHeadersUser'))sendNoCacheHeadersUser();
function cp_upload(string $field,int $uid,string $prefix):string{
 if(empty($_FILES[$field])||(int)($_FILES[$field]['error']??99)!==UPLOAD_ERR_OK)return'';$tmp=(string)$_FILES[$field]['tmp_name'];$info=@getimagesize($tmp);if(!is_uploaded_file($tmp)||$info===false)return'';
 $mime=(string)(mime_content_type($tmp)?:($info['mime']??''));$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime]??'';if(!$ext)return'';$dir=__DIR__.'/uploads/communities';if(!is_dir($dir))@mkdir($dir,0775,true);$name=$prefix.'_'.$uid.'_'.bin2hex(random_bytes(8)).'.'.$ext;return @move_uploaded_file($tmp,$dir.'/'.$name)?'uploads/communities/'.$name:'';
}
function cp_media_type_from_path(string $path):string{
 $ext=strtolower((string)pathinfo((string)(parse_url($path,PHP_URL_PATH)??$path),PATHINFO_EXTENSION));
 return in_array($ext,['mp4','webm','mov','m4v','ogv','ogg'],true)?'video':'image';
}
function cp_post_body_clamp(string $body):array{
 $full=trim($body);
 if($full==='')return['full'=>'','short'=>'','clamped'=>false];
 return['full'=>$full,'short'=>$full,'clamped'=>true];
}
function cp_media_dimensions(string $path,string $type,string $thumb=''):array{
 $source=$type==='video'&&$thumb!==''?$thumb:$path;
 $file=__DIR__.'/'.ltrim((string)preg_replace('~^\./~','',$source),'/');
 if($source!==''&&is_file($file)){
  $size=@getimagesize($file);
  if(is_array($size)&&!empty($size[0])&&!empty($size[1]))return[(int)$size[0],(int)$size[1]];
 }
 if($type!=='video'||$path==='')return[0,0];
 $video=__DIR__.'/'.ltrim((string)preg_replace('~^\./~','',$path),'/');
 $length=is_file($video)?(int)@filesize($video):0;
 $handle=$length>0?@fopen($video,'rb'):false;
 if(!$handle)return[0,0];
 $chunkSize=16*1024*1024;$starts=[0];if($length>$chunkSize)$starts[]=max(0,$length-$chunkSize);
 foreach(array_unique($starts)as$start){
  @fseek($handle,$start);$data=(string)@fread($handle,min($chunkSize,$length-$start));$offset=0;
  while(($at=strpos($data,'tkhd',$offset))!==false){
   $atomStart=$at-4;$atomSize=$atomStart>=0?unpack('N',substr($data,$atomStart,4))[1]??0:0;
   if($atomSize>=20&&$atomStart+$atomSize<=strlen($data)){
    $w=(int)round((unpack('N',substr($data,$atomStart+$atomSize-8,4))[1]??0)/65536);
    $h=(int)round((unpack('N',substr($data,$atomStart+$atomSize-4,4))[1]??0)/65536);
    if($w>0&&$h>0){fclose($handle);return[$w,$h];}
   }
   $offset=$at+4;
  }
 }
 fclose($handle);return[0,0];
}
try{$dbh->exec("CREATE TABLE IF NOT EXISTS community_posts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,community_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,public_post_id BIGINT UNSIGNED NULL,title VARCHAR(180) NOT NULL DEFAULT '',body TEXT NULL,media_path VARCHAR(500) NOT NULL DEFAULT '',hashtags VARCHAR(500) NOT NULL DEFAULT '',visibility ENUM('public','private') NOT NULL DEFAULT 'public',status ENUM('published','pending','removed') NOT NULL DEFAULT 'published',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY community_status(community_id,status,created_at),KEY user_id(user_id),UNIQUE KEY public_post_id(public_post_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$col=$dbh->query("SHOW COLUMNS FROM community_posts LIKE 'visibility'")->fetch(PDO::FETCH_ASSOC);if(!$col)$dbh->exec("ALTER TABLE community_posts ADD visibility ENUM('public','private') NOT NULL DEFAULT 'public' AFTER hashtags");$col=$dbh->query("SHOW COLUMNS FROM community_posts LIKE 'public_post_id'")->fetch(PDO::FETCH_ASSOC);if(!$col)$dbh->exec("ALTER TABLE community_posts ADD public_post_id BIGINT UNSIGNED NULL AFTER user_id, ADD UNIQUE KEY public_post_id(public_post_id)");}catch(Throwable $e){}
try{$dbh->exec("CREATE TABLE IF NOT EXISTS community_invitations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,community_id BIGINT UNSIGNED NOT NULL,invited_user_id BIGINT UNSIGNED NOT NULL,invited_by_user_id BIGINT UNSIGNED NOT NULL,status ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,responded_at DATETIME NULL,KEY invited_status(invited_user_id,status),KEY community_invited(community_id,invited_user_id,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $eInv){}
try{$roleColumn=$dbh->query("SHOW COLUMNS FROM community_members LIKE 'role'")->fetch(PDO::FETCH_ASSOC);if($roleColumn&&stripos((string)($roleColumn['Type']??''),"'manager'")===false)$dbh->exec("ALTER TABLE community_members MODIFY role ENUM('owner','admin','moderator','manager','member') NOT NULL DEFAULT 'member'");}catch(Throwable $eRole){}
if(empty($_SESSION['community_profile_csrf']))$_SESSION['community_profile_csrf']=bin2hex(random_bytes(24));$csrf=(string)$_SESSION['community_profile_csrf'];$notice='';$error='';$inviteToast=((string)($_GET['invited']??'')==='1');$leaveToast=((string)($_GET['left']??'')==='1');
$loadCommunity=function()use($dbh,$communityId,$meId){$s=$dbh->prepare("SELECT c.*,COALESCE(NULLIF(u.name,''),u.username,'Community Owner') owner_name,m.role my_role,m.status my_status,(SELECT COUNT(*) FROM community_members x WHERE x.community_id=c.id AND x.status='active') member_count FROM communities c JOIN users u ON u.id=c.owner_user_id LEFT JOIN community_members m ON m.community_id=c.id AND m.user_id=:me WHERE c.id=:id AND c.status=1 LIMIT 1");$s->execute([':me'=>$meId,':id'=>$communityId]);return$s->fetch(PDO::FETCH_ASSOC)?:null;};
$community=$loadCommunity();if(!$community){http_response_code(404);exit('Community not found.');}$role=(string)($community['my_role']??'');$memberStatus=(string)($community['my_status']??'');$isOwner=$role==='owner'&&(int)$community['owner_user_id']===$meId;$isStaff=in_array($role,['owner','admin','moderator'],true);$canAssignManagers=in_array($role,['owner','admin'],true);$canManagePosts=$isStaff||$role==='manager';$isMember=$memberStatus==='active';
if($community['privacy']==='private'&&!$isMember){http_response_code(403);exit('This is a private community. Join to view its content.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $createdCommunityPostStatus='';$createdCommunityPostPublicId=0;
 try{if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Your session changed. Please try again.');$action=(string)($_POST['action']??'');
  if($action==='join'){if($isMember)throw new RuntimeException('You are already a member.');$pending=$community['join_policy']==='approval';$dbh->prepare("INSERT INTO community_members(community_id,user_id,role,status,joined_at)VALUES(:c,:u,'member',:s,:j)ON DUPLICATE KEY UPDATE status=VALUES(status),joined_at=VALUES(joined_at)")->execute([':c'=>$communityId,':u'=>$meId,':s'=>$pending?'pending':'active',':j'=>$pending?null:date('Y-m-d H:i:s')]);$ownerId=(int)($community['owner_user_id']??0);require_once __DIR__.'/includes/community_invite_notify.php';community_notify_member_joined($dbh,$meId,$ownerId,$communityId,(bool)$pending);$notice=$pending?'Join request sent.':'You joined this community.';}
  elseif($action==='leave'){if($isOwner)throw new RuntimeException('Transfer ownership or delete the community instead.');$ownerId=(int)($community['owner_user_id']??0);$dbh->prepare("DELETE FROM community_members WHERE community_id=:c AND user_id=:u")->execute([':c'=>$communityId,':u'=>$meId]);require_once __DIR__.'/includes/community_invite_notify.php';community_notify_member_left($dbh,$meId,$ownerId,$communityId);header('Location: community_profile.php?id='.$communityId.'&left=1');exit;}
  elseif($action==='save_community'){if(!$isOwner)throw new RuntimeException('Only the Owner can edit this community.');$name=mb_substr(trim((string)($_POST['name']??'')),0,150);$loc=mb_substr(trim((string)($_POST['location_name']??'')),0,160);if($name===''||$loc==='')throw new RuntimeException('Name and city are required.');$cover=cp_upload('cover_image',$meId,'cover');$logo=cp_upload('profile_image',$meId,'logo');$sql='UPDATE communities SET name=:n,description=:d,category=:cat,location_name=:loc,privacy=:pr,join_policy=:jp'.($cover!==''?',cover_image=:cover':'').($logo!==''?',profile_image=:logo':'').' WHERE id=:id AND owner_user_id=:o';$p=[':n'=>$name,':d'=>mb_substr(trim((string)($_POST['description']??'')),0,3000),':cat'=>mb_substr(trim((string)($_POST['category']??'')),0,80),':loc'=>$loc,':pr'=>($_POST['privacy']??'public')==='private'?'private':'public',':jp'=>($_POST['join_policy']??'anyone')==='approval'?'approval':'anyone',':id'=>$communityId,':o'=>$meId];if($cover!=='')$p[':cover']=$cover;if($logo!=='')$p[':logo']=$logo;$dbh->prepare($sql)->execute($p);$notice='Community updated.';}
  elseif($action==='delete_community'){if(!$isOwner||trim((string)($_POST['confirm_name']??''))!==(string)$community['name'])throw new RuntimeException('Enter the exact community name to confirm deletion.');$dbh->prepare('UPDATE communities SET status=0 WHERE id=:id AND owner_user_id=:o')->execute([':id'=>$communityId,':o'=>$meId]);header('Location: community.php?tab=my');exit;}
  elseif($action==='create_post'){if(!$isMember)throw new RuntimeException('Join before posting.');if($community['post_permission']==='staff'&&!$canManagePosts)throw new RuntimeException('Only community post managers can post.');$body=mb_substr(trim((string)($_POST['body']??'')),0,5000);$media=cp_upload('post_media',$meId,'post');if($body===''&&$media==='')throw new RuntimeException('Write something or add a photo.');$status=$community['post_approval']==='approval'&&!$canManagePosts?'pending':'published';$title=mb_substr(trim((string)($_POST['title']??'')),0,180);$tags=mb_substr(trim((string)($_POST['hashtags']??'')),0,500);$dbh->prepare('INSERT INTO community_posts(community_id,user_id,title,body,media_path,hashtags,status)VALUES(:c,:u,:t,:b,:m,:h,:s)')->execute([':c'=>$communityId,':u'=>$meId,':t'=>$title,':b'=>$body,':m'=>$media,':h'=>$tags,':s'=>$status]);$communityPostId=(int)$dbh->lastInsertId();try{$stPub=$dbh->prepare("INSERT INTO public_posts(user_id,title,description,body,visibility,created_at,updated_at,is_deleted)VALUES(:u,:t,NULL,:b,'public',NOW(),NOW(),1)");$stPub->execute([':u'=>$meId,':t'=>$title?:null,':b'=>$body?:null]);$publicBridgeId=(int)$dbh->lastInsertId();if($publicBridgeId>0&&$communityPostId>0){$dbh->prepare('UPDATE community_posts SET public_post_id=:pub WHERE id=:id AND user_id=:u')->execute([':pub'=>$publicBridgeId,':id'=>$communityPostId,':u'=>$meId]);if($media!=='')$dbh->prepare("INSERT INTO public_post_attachments(post_id,type,file_path,thumb_path,created_at)VALUES(:p,:t,:f,NULL,NOW())")->execute([':p'=>$publicBridgeId,':t'=>cp_media_type_from_path($media),':f'=>$media]);$createdCommunityPostPublicId=$publicBridgeId;}}catch(Throwable $eCreateBridge){}$createdCommunityPostStatus=$status;$notice=$status==='pending'?'Post submitted for approval.':'Post published.';}
  elseif($action==='save_post'){$pid=(int)($_POST['post_id']??0);$s=$dbh->prepare('SELECT user_id,public_post_id FROM community_posts WHERE id=:p AND community_id=:c');$s->execute([':p'=>$pid,':c'=>$communityId]);$postRow=$s->fetch(PDO::FETCH_ASSOC)?:[];$author=(int)($postRow['user_id']??0);if(!$author||($author!==$meId&&!$canManagePosts))throw new RuntimeException('You cannot edit this post.');$postTitle=mb_substr(trim((string)($_POST['title']??'')),0,180);$postBody=mb_substr(trim((string)($_POST['body']??'')),0,5000);$postTags=mb_substr(trim((string)($_POST['hashtags']??'')),0,500);$dbh->prepare('UPDATE community_posts SET title=:t,body=:b,hashtags=:h WHERE id=:p AND community_id=:c')->execute([':t'=>$postTitle,':b'=>$postBody,':h'=>$postTags,':p'=>$pid,':c'=>$communityId]);$publicPostId=(int)($postRow['public_post_id']??0);if($publicPostId>0){try{$dbh->prepare('UPDATE public_posts SET title=:t,body=:b,updated_at=NOW() WHERE id=:p')->execute([':t'=>$postTitle?:null,':b'=>$postBody?:null,':p'=>$publicPostId]);}catch(Throwable $eBridgeUpdate){}}$notice='Post updated.';}
  elseif($action==='delete_post'){$pid=(int)($_POST['post_id']??0);$s=$dbh->prepare('SELECT user_id FROM community_posts WHERE id=:p AND community_id=:c');$s->execute([':p'=>$pid,':c'=>$communityId]);$author=(int)$s->fetchColumn();if(!$author||($author!==$meId&&!$canManagePosts))throw new RuntimeException('You cannot delete this post.');$dbh->prepare("UPDATE community_posts SET status='removed' WHERE id=:p AND community_id=:c")->execute([':p'=>$pid,':c'=>$communityId]);$notice='Post deleted.';}
  elseif($action==='set_member_role'){if(!$canAssignManagers)throw new RuntimeException('Only the Owner or an Admin can assign managers.');$targetUserId=(int)($_POST['member_user_id']??0);$newRole=(string)($_POST['member_role']??'member');if($targetUserId<=0||$targetUserId===$meId||!in_array($newRole,['manager','member'],true))throw new RuntimeException('Choose a valid member and role.');$stCurrentRole=$dbh->prepare("SELECT role FROM community_members WHERE community_id=:c AND user_id=:u AND status='active' AND role IN('member','manager') LIMIT 1");$stCurrentRole->execute([':c'=>$communityId,':u'=>$targetUserId]);$currentRole=(string)($stCurrentRole->fetchColumn()?:'');if($currentRole==='')throw new RuntimeException('That member role could not be changed.');if($currentRole!==$newRole){$stRole=$dbh->prepare("UPDATE community_members SET role=:role WHERE community_id=:c AND user_id=:u AND status='active' AND role IN('member','manager')");$stRole->execute([':role'=>$newRole,':c'=>$communityId,':u'=>$targetUserId]);}$notice=$newRole==='manager'?'Manager access granted.':'Manager access removed.';}
  elseif(in_array($action,['approve_join_request','deny_join_request'],true)){if(!$isStaff)throw new RuntimeException('Only community staff can answer join requests.');$targetUserId=(int)($_POST['member_user_id']??0);if($targetUserId<=0)throw new RuntimeException('Join request not found.');$stPending=$dbh->prepare("SELECT status FROM community_members WHERE community_id=:c AND user_id=:u LIMIT 1");$stPending->execute([':c'=>$communityId,':u'=>$targetUserId]);if((string)($stPending->fetchColumn()?:'')!=='pending')throw new RuntimeException('This join request was already answered.');$approved=$action==='approve_join_request';if($approved){$dbh->prepare("UPDATE community_members SET status='active',role='member',joined_at=NOW() WHERE community_id=:c AND user_id=:u AND status='pending'")->execute([':c'=>$communityId,':u'=>$targetUserId]);}else{$dbh->prepare("DELETE FROM community_members WHERE community_id=:c AND user_id=:u AND status='pending'")->execute([':c'=>$communityId,':u'=>$targetUserId]);}require_once __DIR__.'/includes/community_invite_notify.php';community_notify_join_response($dbh,$meId,$targetUserId,$communityId,$approved);$notice=$approved?'Join request accepted.':'Join request denied.';}
  elseif($action==='add_rule'){if(!$isStaff)throw new RuntimeException('Staff permission required.');$rule=mb_substr(trim((string)($_POST['rule']??'')),0,280);if($rule==='')throw new RuntimeException('Enter a rule.');$dbh->prepare('INSERT INTO community_rules(community_id,rule_text,sort_order)VALUES(:c,:r,99)')->execute([':c'=>$communityId,':r'=>$rule]);$notice='Rule added.';}
  elseif($action==='delete_rule'){if(!$isStaff)throw new RuntimeException('Staff permission required.');$dbh->prepare('DELETE FROM community_rules WHERE id=:r AND community_id=:c')->execute([':r'=>(int)($_POST['rule_id']??0),':c'=>$communityId]);$notice='Rule removed.';}elseif($action==='invite'){if(!$isMember)throw new RuntimeException('Join this community before inviting friends.');$rawIds=$_POST['invite_user_ids']??[];if(!is_array($rawIds))$rawIds=[$rawIds];$inviteIds=[];foreach($rawIds as$v){$uid=(int)$v;if($uid>0&&$uid!==$meId)$inviteIds[$uid]=$uid;}$inviteIds=array_values($inviteIds);if(!$inviteIds)throw new RuntimeException('Select at least one friend from your contacts.');$sent=0;$skipped=0;foreach($inviteIds as$uid){try{$stC=$dbh->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id=:me AND friend_user_id=:f LIMIT 1');$stC->execute([':me'=>$meId,':f'=>$uid]);if(!$stC->fetchColumn()){$skipped++;continue;}$stM=$dbh->prepare("SELECT status FROM community_members WHERE community_id=:c AND user_id=:u LIMIT 1");$stM->execute([':c'=>$communityId,':u'=>$uid]);$memStatus=(string)($stM->fetchColumn()?:'');if($memStatus==='active'){$skipped++;continue;}$stP=$dbh->prepare("SELECT id FROM community_invitations WHERE community_id=:c AND invited_user_id=:u AND status='pending' LIMIT 1");$stP->execute([':c'=>$communityId,':u'=>$uid]);if($stP->fetchColumn()){$skipped++;continue;}$stPrev=$dbh->prepare("SELECT id FROM community_invitations WHERE community_id=:c AND invited_user_id=:u AND status IN('declined','cancelled') ORDER BY id DESC LIMIT 1");$stPrev->execute([':c'=>$communityId,':u'=>$uid]);$prevInviteId=(int)($stPrev->fetchColumn()?:0);if($prevInviteId>0){$dbh->prepare("UPDATE community_invitations SET status='pending',invited_by_user_id=:by,created_at=NOW(),responded_at=NULL WHERE id=:id LIMIT 1")->execute([':by'=>$meId,':id'=>$prevInviteId]);$inviteRowId=$prevInviteId;}else{$dbh->prepare("INSERT INTO community_invitations(community_id,invited_user_id,invited_by_user_id,status,created_at)VALUES(:c,:u,:by,'pending',NOW())")->execute([':c'=>$communityId,':u'=>$uid,':by'=>$meId]);$inviteRowId=(int)$dbh->lastInsertId();}$sent++;try{$stMe=$dbh->prepare("SELECT COALESCE(NULLIF(name,''),NULLIF(username,''),'Someone') FROM users WHERE id=:id LIMIT 1");$stMe->execute([':id'=>$meId]);$senderLabel=(string)$stMe->fetchColumn();$stThem=$dbh->prepare('SELECT username,email FROM users WHERE id=:id LIMIT 1');$stThem->execute([':id'=>$uid]);$them=$stThem->fetch(PDO::FETCH_ASSOC)?:[];$receivers=[];foreach(['username','email']as$rk){$rv=trim((string)($them[$rk]??''));if($rv!=='')$receivers[$rv]=$rv;}$receivers=array_values($receivers);$cname=trim((string)$community['name']);if($cname==='')$cname='a community';$msg='invited you to join '.$cname;$suffix=' [r:cinv]'.($communityId>0?(' [cc:'.$communityId.']'):'').($inviteRowId>0?(' [ci:'.$inviteRowId.']'):'');$room=max(0,100-mb_strlen($suffix));if(mb_strlen($msg)>$room)$msg=rtrim(mb_substr($msg,0,$room));$notitype=mb_substr($msg.$suffix,0,100);if($senderLabel!==''&&$receivers){$insNoti=$dbh->prepare('INSERT INTO notification(notiuser,notireceiver,notitype,is_read)VALUES(:s,:r,:t,0)');foreach($receivers as$receiverKey){$insNoti->execute([':s'=>$senderLabel,':r'=>$receiverKey,':t'=>$notitype]);}}}catch(Throwable $eNoti){}}catch(Throwable $eOne){$skipped++;}}if($sent<=0)throw new RuntimeException($skipped?'Those friends are already members or already invited.':'Unable to send invites.');$tabKeep=trim((string)($_GET['tab']??'home'));$redir='community_profile.php?id='.$communityId.($tabKeep!==''&&$tabKeep!=='home'?('&tab='.rawurlencode($tabKeep)):'').'&invited=1';header('Location: '.$redir);exit;}
 }catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Unable to complete that action.';}
 if(($action??'')==='create_post'&&$createdCommunityPostStatus==='published'&&$createdCommunityPostPublicId>0){require_once __DIR__.'/includes/community_invite_notify.php';community_notify_active_members($dbh,$meId,$communityId,'posted a new update in ','cpost',$createdCommunityPostPublicId);}
 $community=$loadCommunity();$role=(string)($community['my_role']??'');$memberStatus=(string)($community['my_status']??'');$isOwner=$role==='owner'&&(int)$community['owner_user_id']===$meId;$isStaff=in_array($role,['owner','admin','moderator'],true);$canAssignManagers=in_array($role,['owner','admin'],true);$canManagePosts=$isStaff||$role==='manager';$isMember=$memberStatus==='active';
}
$rules=[];$members=[];$pendingRequests=[];$posts=[];try{$s=$dbh->prepare('SELECT * FROM community_rules WHERE community_id=:c ORDER BY sort_order,id');$s->execute([':c'=>$communityId]);$rules=$s->fetchAll(PDO::FETCH_ASSOC)?:[];$s=$dbh->prepare("SELECT m.*,COALESCE(NULLIF(u.name,''),u.username,'Member') display_name FROM community_members m JOIN users u ON u.id=m.user_id WHERE m.community_id=:c AND m.status='active' ORDER BY FIELD(m.role,'owner','admin','moderator','manager','member'),m.joined_at DESC LIMIT 100");$s->execute([':c'=>$communityId]);$members=$s->fetchAll(PDO::FETCH_ASSOC)?:[];if($isStaff){$s=$dbh->prepare("SELECT m.user_id,m.created_at,COALESCE(NULLIF(u.name,''),u.username,'Member') display_name FROM community_members m JOIN users u ON u.id=m.user_id WHERE m.community_id=:c AND m.status='pending' ORDER BY m.created_at ASC LIMIT 100");$s->execute([':c'=>$communityId]);$pendingRequests=$s->fetchAll(PDO::FETCH_ASSOC)?:[];}$status=$canManagePosts?"p.status IN('published','pending')":($isMember?"p.status='published'":"p.status='published' AND p.visibility='public'");$s=$dbh->prepare("SELECT p.*,COALESCE(NULLIF(u.name,''),u.username,'Member') display_name FROM community_posts p JOIN users u ON u.id=p.user_id WHERE p.community_id=:c AND $status ORDER BY p.created_at DESC");$s->execute([':c'=>$communityId]);$posts=$s->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}$communityJoinedAt='';foreach($members as$communityMember){if((int)($communityMember['user_id']??0)===$meId){$communityJoinedAt=(string)($communityMember['joined_at']??'');break;}}
$posts=array_values(array_filter($posts,static fn($post)=>(string)($post['visibility']??'public')==='public'));

$inviteFriends=[];
if($isMember){
 try{
  $stF=$dbh->prepare("SELECT u.id,COALESCE(NULLIF(uc.display_name,''),NULLIF(u.name,''),NULLIF(u.username,''),'Friend') display_name FROM user_contacts uc JOIN users u ON u.id=uc.friend_user_id WHERE uc.owner_user_id=:me AND u.status=1 ORDER BY display_name ASC");
  $stF->execute([':me'=>$meId]);
  $allFriends=$stF->fetchAll(PDO::FETCH_ASSOC)?:[];
  $memberIds=[];$stEx=$dbh->prepare("SELECT user_id FROM community_members WHERE community_id=:c AND status IN('active','pending','blocked')");$stEx->execute([':c'=>$communityId]);
  foreach($stEx->fetchAll(PDO::FETCH_COLUMN)?:[] as$mid)$memberIds[(int)$mid]=true;
  $pendingInviteIds=[];$stPi=$dbh->prepare("SELECT invited_user_id FROM community_invitations WHERE community_id=:c AND status='pending'");$stPi->execute([':c'=>$communityId]);
  foreach($stPi->fetchAll(PDO::FETCH_COLUMN)?:[] as$pid)$pendingInviteIds[(int)$pid]=true;
  foreach($allFriends as$row){
   $fid=(int)($row['id']??0);
   if($fid<=0||isset($memberIds[$fid])||isset($pendingInviteIds[$fid]))continue;
   $inviteFriends[]=$row;
  }
 }catch(Throwable $eFriends){$inviteFriends=[];}
}

// Normalize older community-only posts into the hidden public-post model used by
// the shared Discover action system. The public row stays deleted from regular
// feeds while providing tags, mentions, favorites, shares, and modal actions.
foreach($posts as &$bridgePost){
 if((int)($bridgePost['public_post_id']??0)>0)continue;
 $authorId=(int)($bridgePost['user_id']??0);
 if($authorId<=0)continue;
 try{
  $stBridge=$dbh->prepare("INSERT INTO public_posts(user_id,title,description,body,visibility,created_at,updated_at,is_deleted)VALUES(:u,:t,NULL,:b,:v,:c,:d,1)");
  $stBridge->execute([':u'=>$authorId,':t'=>(string)$bridgePost['title']?:null,':b'=>(string)$bridgePost['body']?:null,':v'=>((string)($bridgePost['visibility']??'public')==='private'?'private':'public'),':c'=>(string)$bridgePost['created_at'],':d'=>(string)$bridgePost['updated_at']]);
  $publicBridgeId=(int)$dbh->lastInsertId();
  if($publicBridgeId>0){
   $dbh->prepare('UPDATE community_posts SET public_post_id=:pub WHERE id=:id AND user_id=:u')->execute([':pub'=>$publicBridgeId,':id'=>(int)$bridgePost['id'],':u'=>$authorId]);
   $bridgePost['public_post_id']=$publicBridgeId;
   $mediaBridge=trim((string)($bridgePost['media_path']??''));
   if($mediaBridge!=='')$dbh->prepare("INSERT INTO public_post_attachments(post_id,type,file_path,thumb_path,created_at)VALUES(:p,:t,:f,NULL,NOW())")->execute([':p'=>$publicBridgeId,':t'=>cp_media_type_from_path($mediaBridge),':f'=>$mediaBridge]);
  }
 }catch(Throwable $eBridge){}
}
unset($bridgePost);
$postAttachmentsByPublic=[];
$publicIds=array_values(array_filter(array_map(static fn($post)=>(int)($post['public_post_id']??0),$posts)));
if($publicIds){
 try{
  $marks=implode(',',array_fill(0,count($publicIds),'?'));
  $stMedia=$dbh->prepare("SELECT post_id,type,file_path,thumb_path FROM public_post_attachments WHERE post_id IN ($marks) AND type IN('image','video') ORDER BY post_id,id");
  $stMedia->execute($publicIds);
  foreach(($stMedia->fetchAll(PDO::FETCH_ASSOC)?:[])as$attachment){
   if(cp_media_type_from_path((string)($attachment['file_path']??''))==='video')$attachment['type']='video';
   [$mediaWidth,$mediaHeight]=cp_media_dimensions((string)($attachment['file_path']??''),(string)($attachment['type']??''),(string)($attachment['thumb_path']??''));
   $attachment['width']=$mediaWidth;$attachment['height']=$mediaHeight;
   $attachment['shape']=(($attachment['type']??'')==='video'?'landscape':'square');
   $shapeSource=(string)(($attachment['type']??'')==='video'?($attachment['thumb_path']??''):($attachment['file_path']??''));
   if($shapeSource!==''){
    $shapeFile=__DIR__.'/'.ltrim((string)preg_replace('~^\./~','',$shapeSource),'/');
    $shapeSize=is_file($shapeFile)?@getimagesize($shapeFile):false;
    if(is_array($shapeSize)&&!empty($shapeSize[0])&&!empty($shapeSize[1])){
     $attachment['shape']=$shapeSize[1]>$shapeSize[0]*1.1?'portrait':($shapeSize[0]>$shapeSize[1]*1.15?'landscape':'square');
    }
   }elseif(($attachment['type']??'')==='video')$attachment['shape']='landscape';
   if($mediaWidth>0&&$mediaHeight>0)$attachment['shape']=$mediaHeight>$mediaWidth*1.1?'portrait':($mediaWidth>$mediaHeight*1.15?'landscape':'square');
   $postAttachmentsByPublic[(int)$attachment['post_id']][]=$attachment;
  }
 }catch(Throwable $eMedia){}
}
/* Older/imported community posts can have media_path without a matching
   public_post_attachments row. Keep those cards in the same loading flow so
   refresh never renders an apparently empty post while its media initializes. */
foreach($posts as $postWithMedia){
 $fallbackPublicId=(int)($postWithMedia['public_post_id']??0);
 $fallbackPath=trim((string)($postWithMedia['media_path']??''));
 if($fallbackPublicId<=0||$fallbackPath===''||!empty($postAttachmentsByPublic[$fallbackPublicId]))continue;
 $fallbackType=cp_media_type_from_path($fallbackPath);
 [$fallbackWidth,$fallbackHeight]=cp_media_dimensions($fallbackPath,$fallbackType,'');
 $fallbackShape='landscape';
 if($fallbackWidth>0&&$fallbackHeight>0)$fallbackShape=$fallbackHeight>$fallbackWidth*1.1?'portrait':($fallbackWidth>$fallbackHeight*1.15?'landscape':'square');
 $postAttachmentsByPublic[$fallbackPublicId][]=array('post_id'=>$fallbackPublicId,'type'=>$fallbackType,'file_path'=>$fallbackPath,'thumb_path'=>null,'width'=>$fallbackWidth,'height'=>$fallbackHeight,'shape'=>$fallbackShape);
}
$tab=(string)($_GET['tab']??'home');if(!in_array($tab,['home','posts','media','members','events','about'],true))$tab='home';if($tab==='members'&&!$isMember)$tab='home';$cover=(string)$community['cover_image'];$logo=(string)$community['profile_image'];$initial=mb_strtoupper(mb_substr((string)$community['name'],0,1));
?><!doctype html><html <?=function_exists('app_html_lang_attrs')?app_html_lang_attrs():'lang="en"'?>>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h((string)$community['name'])?></title>
<?php theme_prefs_print_head_bootstrap($dbh,$meId); post_action_thin_icons_render_css();?>
<link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet"><link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
<script>(function(){var skeleton='<div class="community-loading-card-skeleton" aria-hidden="true"><div class="community-loading-card-head"><span class="community-loading-card-avatar"></span><span class="community-loading-card-copy"><i></i><i></i></span><span class="community-loading-card-fries"><i></i><i></i><i></i></span></div><div class="community-loading-card-media"></div><div class="community-loading-card-actions"><i></i><i></i><i></i><i></i></div></div>';function prepare(root){var cards=[];if(root&&root.nodeType===1&&root.closest){var owner=root.closest('article.post');if(owner)cards.push(owner);}if(root&&root.querySelectorAll)cards=cards.concat(Array.from(root.querySelectorAll('article.post')));cards.forEach(function(card){if(!card.querySelector(':scope > .post-media')||card.querySelector(':scope > .community-loading-card-skeleton'))return;card.classList.add('community-card-media-loading');card.insertAdjacentHTML('afterbegin',skeleton);});}new MutationObserver(function(records){records.forEach(function(record){record.addedNodes.forEach(prepare);});}).observe(document.documentElement,{childList:true,subtree:true});})();</script>
<script>window.__communityFeedEnd=<?=json_encode($communityJoinedAt!==''?'Joined '.(string)$community['name'].' '.date('F j, Y',strtotime($communityJoinedAt)):'You’ve reached the end of this community’s posts.',JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;window.__communityPostPublicIds=<?=json_encode(array_map(static fn($post)=>(int)($post['public_post_id']??0),$posts))?>;window.__communityPostAttachments=<?=json_encode($postAttachmentsByPublic,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;(function(){var groups=Object.values(window.__communityPostAttachments||{}).slice(0,4);groups.forEach(function(group){var item=group&&group[0];if(!item||!item.file_path||String(item.type||'').toLowerCase()==='video')return;var image=new Image();image.fetchPriority='high';image.decoding='async';image.src=item.file_path;});}());</script>
<script>(function(){function refreshReadMore(){document.querySelectorAll('.community-post-copy').forEach(function(copy){var shortText=copy.querySelector('.community-post-copy-short');var button=copy.querySelector('.community-post-readmore');if(!shortText||!button)return;var overflowing=shortText.scrollHeight>shortText.clientHeight+1;button.hidden=!overflowing;copy.classList.toggle('has-overflow',overflowing);});}document.addEventListener('DOMContentLoaded',refreshReadMore);window.addEventListener('load',refreshReadMore);window.addEventListener('resize',refreshReadMore);document.addEventListener('click',function(e){var button=e.target.closest('.community-post-readmore');if(!button)return;var copy=button.closest('.community-post-copy');if(!copy)return;var expanded=copy.getAttribute('data-expanded')==='1';var shortText=copy.querySelector('.community-post-copy-short');var fullText=copy.querySelector('.community-post-copy-full');copy.setAttribute('data-expanded',expanded?'0':'1');button.setAttribute('aria-expanded',expanded?'false':'true');button.textContent=expanded?'Read more':'Show less';if(shortText)shortText.hidden=!expanded;if(fullText)fullText.hidden=expanded;});})();</script>
<script defer src="./js/community_profile_ui.js?v=202609162355"></script><script defer src="./js/community_post_engagement.js?v=202609150752"></script>
<style>
:root{--community-loading-media-bg:#f1f1f1;--community-loading-detail-bg:#e5e7eb}
html.dark-auto,html[data-theme="dark"],body.dark-auto{--community-loading-media-bg:#4e4e4e75;--community-loading-detail-bg:#4e4e4e75}
.community-top-search{display:none!important}
html,body{height:auto!important;max-height:none!important;overflow-x:hidden;overflow-y:auto!important}
body .page{margin-left:var(--feed-rail-w,72px);padding:0 24px;background:var(--msb-palette-bg,#18191a);overflow:visible!important}
body .layout{width:min(1440px,100%);max-width:1440px;margin:0 auto;display:grid;grid-template-columns:minmax(0,760px) minmax(300px,390px);justify-content:center;align-items:start;gap:16px}
body .layout>div{display:contents}
body .hero{grid-column:1/-1;grid-row:1;overflow:visible;border:0!important;border-radius:0!important;background:var(--msb-palette-surface,var(--msb-palette-bg,#242526))!important}
body .cover{left:50%;width:calc(100vw - var(--feed-rail-w,72px) - 48px);height:clamp(220px,26vw,360px);transform:translateX(-50%);border-radius:0 0 10px 10px;background-size:cover!important;background-position:center!important}
.cover-edit{right:20px;bottom:18px;background:var(--msb-palette-surface-2,#3a3b3c)!important;color:var(--msb-palette-text,#fff)!important;border:0!important}
body .identity{min-height:160px;padding:18px 280px 8px 285px;border-bottom:0}
body .identity::after{content:"";position:absolute;left:50%;bottom:0;width:calc(100vw - var(--feed-rail-w,72px) - 48px);height:1px;transform:translateX(-50%);background:var(--msb-palette-border,#3e4042);pointer-events:none}
body .avatar{left:28px;top:-72px;width:200px;height:200px;border:6px solid var(--msb-palette-surface,var(--msb-palette-bg,#242526));font-size:72px}html body .avatar.has-photo,html[data-msb-appearance] body .avatar.has-photo,html.dark-auto body .avatar.has-photo,body.dark-auto .avatar.has-photo{background:transparent!important;background-image:none!important;background-color:transparent!important;color:transparent!important}.avatar.has-photo img{width:100%;height:100%;object-fit:cover;border-radius:50%;display:block}
body .avatar-edit{left:175px;top:70px;width:44px;height:44px;background:var(--msb-palette-surface-2,#3a3b3c);z-index:2}
.identity h1{font-size:36px;line-height:1.1;margin:0 0 9px}.identity>.muted{font-size:16px;font-weight:700}.identity p{font-size:16px;color:var(--msb-palette-text-muted,#b0b3b8);max-width:760px}
.identity-actions{right:24px;top:28px}.identity-actions .btn{background:var(--msb-palette-surface-2,#3a3b3c);color:var(--msb-palette-text,#e4e6eb);border:0}.identity-actions .btn.primary{background:var(--msb-palette-action,#2374e1);color:var(--msb-palette-btn-text,#fff)}
.community-tags{margin-top:8px}.community-tags span{background:transparent;padding:0;color:var(--msb-palette-text-muted,#b0b3b8)}
body .tabs{width:760px;max-width:calc(100% - 406px);height:56px;align-items:end;gap:8px;padding:0 18px;margin-top:0;border-top:1px solid var(--msb-palette-border,#d8dee8);border-left:1px solid var(--msb-palette-border,#d8dee8);border-right:1px solid var(--msb-palette-border,#d8dee8)}.tabs a{padding:14px 18px 12px;border-radius:7px 7px 0 0}.tabs a:hover{background:var(--msb-palette-hover-bg,var(--msb-palette-surface-2,#3a3b3c))}
body .tabs{position:relative;overflow:visible!important}body .tabs::before,body .tabs::after{content:"";position:absolute;top:100%;height:32px;width:1px;background:var(--msb-palette-border,#d8dee8);pointer-events:none}body .tabs::before{left:-1px}body .tabs::after{right:-1px}
body .content{grid-column:1;grid-row:2;margin-top:16px;min-width:0;align-self:stretch;border-left:1px solid var(--msb-palette-border,#d8dee8);border-right:1px solid var(--msb-palette-border,#d8dee8);box-sizing:border-box;overflow:visible!important}body .side{grid-column:2;grid-row:2;margin-top:-55px;position:static;align-self:start;gap:16px;overflow:visible!important;max-height:none}
body .main,body .side-card,body .composer,body .post,body .manage{background:var(--msb-palette-surface,var(--msb-palette-bg,#242526));border:0;border-radius:10px;color:var(--msb-palette-text,#e4e6eb)}
body .content article.post .community-post-media-stage{
  margin:12px 0 0!important;
  display:flex!important;
  justify-content:flex-start!important;
  align-items:flex-start!important;
  overflow:visible!important;
  background:transparent!important;
}
body .content article.post .community-post-media-stage.is-loading{
  width:min(calc(100% - 36px),720px)!important;
  min-height:0!important;
  margin:12px 18px 0!important;
  aspect-ratio:4/3;
  background:var(--community-loading-media-bg,#f1f1f1)!important;
  border-radius:7px!important;
  overflow:hidden!important;
  box-shadow:inset 0 -22px 28px rgba(15,23,42,.025);
}
body .content article.post .community-post-media-stage.is-loading.is-landscape{
  width:min(calc(100% - 36px),720px)!important;
  aspect-ratio:4/3;
}
body .content article.post .community-post-media-stage.is-loading.is-square{
  width:min(calc(100% - 36px),620px)!important;
  aspect-ratio:1/1;
}
body .content article.post .community-post-media-stage.is-loading.is-portrait{
  width:min(calc(100% - 36px),320px)!important;
  aspect-ratio:9/16;
}
body .content article.post .community-post-media-stage.is-loading .community-post-media-slides{
  opacity:0!important;
}
body .content article.post.community-card-media-loading{
  position:relative!important;
}
body .content article.post.community-card-media-loading>.community-loading-card-skeleton{
  display:block!important;
  visibility:visible!important;
  opacity:1!important;
}
body .content article.post.community-card-media-loading>.community-post-media-stage{
  position:absolute!important;
  left:18px!important;
  top:82px!important;
  visibility:hidden!important;
  opacity:0!important;
  pointer-events:none!important;
}
body .content article.post.community-card-media-loading>:not(.community-loading-card-skeleton):not(.community-post-media-stage){
  display:none!important;
}
.community-loading-card-skeleton{display:none;width:calc(100% - 36px);margin:16px 18px 14px;box-sizing:border-box}
.community-loading-card-head{display:flex;align-items:center;gap:11px;margin:0 0 16px}
.community-loading-card-avatar{width:46px;height:46px;flex:0 0 46px;border-radius:50%;background:var(--community-loading-detail-bg,#e5e7eb)}
.community-loading-card-copy{display:grid;gap:7px;flex:1}
.community-loading-card-copy i,.community-loading-card-fries i,.community-loading-card-actions i{display:block;border-radius:999px;background:var(--community-loading-detail-bg,#e5e7eb)}
.community-loading-card-copy i{height:10px}.community-loading-card-copy i:first-child{width:150px;max-width:70%}.community-loading-card-copy i:last-child{width:95px;max-width:45%}
.community-loading-card-fries{display:grid;gap:4px;width:22px;flex:0 0 22px}.community-loading-card-fries i{height:3px}.community-loading-card-fries i:nth-child(2){width:16px}.community-loading-card-fries i:nth-child(3){width:10px}
.community-loading-card-media{width:100%;max-width:100%;aspect-ratio:4/3;border-radius:7px;background:var(--community-loading-media-bg,#f1f1f1);box-shadow:inset 0 -22px 28px rgba(15,23,42,.025);box-sizing:border-box}
.community-loading-card-actions{display:flex;gap:22px;margin-top:14px;padding:12px 0 0;border-top:1px solid var(--msb-palette-border,#d0d3da)}
.community-loading-card-actions i{width:34px;height:12px}.community-loading-card-actions i:last-child{margin-left:auto}
body .content article.post .community-post-media-slides{
  width:auto!important;
  max-width:calc(100% - 36px)!important;
  margin:0 18px 0 18px!important;
  background:transparent!important;
  border-radius:0!important;
  overflow:visible!important;
  clip-path:none!important;
  -webkit-clip-path:none!important;
}
body .content article.post .community-post-media-slide{
  display:flex!important;
  justify-content:flex-start!important;
  align-items:center!important;
  width:auto!important;
  max-width:100%!important;
  border-radius:0!important;
  overflow:visible!important;
  background:transparent!important;
}
body .content article.post .community-post-media-clip{
  display:inline-block!important;
  width:fit-content!important;
  max-width:100%!important;
  line-height:0!important;
  border-radius:8px!important;
  overflow:hidden!important;
  clip-path:inset(0 round 8px)!important;
  -webkit-clip-path:inset(0 round 8px)!important;
  mask-image:none!important;
  -webkit-mask-image:none!important;
  background:transparent!important;
}
body .content article.post .community-post-media-slide.is-landscape .community-post-media-clip,
body .content article.post .community-post-media-slide.is-portrait .community-post-media-clip,
body .content article.post .community-post-media-slide.is-square .community-post-media-clip{
  width:fit-content!important;
}
body .content article.post .community-post-media-stage .post-media,
body .content article.post .community-post-media-slide .post-media,
body .content article.post .community-post-media-clip .post-media{
  display:block!important;
  width:auto!important;
  max-width:min(100%,720px)!important;
  height:auto!important;
  max-height:min(56vh,calc(100dvh - 220px))!important;
  border-radius:8px!important;
  overflow:hidden!important;
  clip-path:inset(0 round 8px)!important;
  -webkit-clip-path:inset(0 round 8px)!important;
  mask-image:none!important;
  -webkit-mask-image:none!important;
  object-fit:contain!important;
  object-position:left center!important;
  background:transparent!important;
}
body .content article.post .community-post-media-stage.is-portrait .post-media,
body .content article.post .community-post-media-slide.is-portrait .post-media{
  max-height:min(72vh,calc(100dvh - 120px))!important;
}
.side-card{padding:20px;box-shadow:none}.side-card h2{font-size:21px}.side-card p,.fact{font-size:15px;line-height:1.35}.side-card-head button{font-size:15px}
.side-card .rule{min-height:42px;gap:12px;margin:8px 0;padding:3px 0}
.side-card .rule>span:nth-child(2){min-width:0;line-height:1.25}
.side-card .rule>form{margin-left:auto;flex:0 0 auto}
.side-card .rule>form button{width:32px;height:32px;padding:0;border:1px solid var(--msb-palette-border,#cbd5e1);border-radius:8px;background:var(--msb-palette-surface-2,#f3f4f6);color:var(--msb-palette-text,#0f172a);font-size:18px;font-weight:700;cursor:pointer}
.side-card .rule>form button:hover{background:#fee2e2;color:#b42318;border-color:#fecaca}
.side-card form:has(input[name="rule"]){display:flex;align-items:center;gap:10px;width:100%;margin-top:14px}
.side-card form:has(input[name="rule"]) input[name="rule"]{flex:1 1 auto;min-width:0;height:42px;padding:0 12px;border:1px solid var(--msb-palette-border,#cbd5e1);border-radius:10px;background:var(--msb-palette-input-bg,var(--msb-palette-surface,#fff));color:var(--msb-palette-text,#0f172a);font:inherit}
.side-card form:has(input[name="rule"]) .btn{flex:0 0 auto;height:42px;margin:0!important;padding:0 16px;white-space:nowrap}
body .composer.compact{padding:16px;margin-bottom:16px}.composer-prompt{padding-bottom:14px;border-bottom:1px solid var(--msb-palette-border,#3e4042)}.composer-mini-avatar{width:46px;height:46px}.composer-placeholder{font-size:18px;background:var(--msb-palette-surface-2,#3a3b3c)}.composer-tools{padding:14px 14px 0;font-size:15px}.composer-tools span:nth-child(n+4){display:none}
body .feed-filter{height:105px;padding:16px 20px;margin:16px 0 16px;border:0;border-radius:10px;background:var(--msb-palette-bg,#171d24)!important;background-color:var(--msb-palette-bg,#171d24)!important;background-image:none!important;position:relative;align-items:flex-start}.feed-filter:before{content:'Posts';font-size:24px;color:var(--msb-palette-text,#e4e6eb)}.feed-filter span:not(.sort){position:absolute;bottom:0;width:50%;padding:14px;text-align:center;background:var(--msb-palette-bg,#171d24)!important;background-color:var(--msb-palette-bg,#171d24)!important}.feed-filter span.active{left:0;border-bottom:3px solid var(--msb-palette-action,#2374e1);background:var(--msb-palette-bg,#171d24)!important}.feed-filter span:nth-of-type(2){left:50%}.feed-filter span:nth-of-type(3),.feed-filter span:nth-of-type(4){display:none}.feed-filter .sort{margin-left:auto;padding:9px 13px;border-radius:7px;background:var(--msb-palette-bg,#171d24)!important;background-color:var(--msb-palette-bg,#171d24)!important;color:var(--msb-palette-text,#e4e6eb)}
body .post{padding:0;overflow:hidden;border-radius:10px}.post-head{padding:16px 18px 8px;font-size:15px}.post>h3,.post>p,.post>details{margin-left:18px;margin-right:18px}.post>p{font-size:16px;line-height:1.45}.post-media{border-radius:8px;display:block;width:auto;max-width:min(100%,720px);max-height:min(56vh,calc(100dvh - 220px));height:auto;object-fit:contain}.tags{padding-bottom:12px}.post details{padding-bottom:16px}
body .content{padding:0 12px 40px;border-left:1px solid var(--msb-palette-border,#d8dee8)!important;border-right:1px solid var(--msb-palette-border,#d8dee8)!important;background:var(--msb-palette-bg,#171d24)!important;background-color:var(--msb-palette-bg,#171d24)!important;overflow:visible!important}
body article.post{margin:0 0 16px;overflow:visible;border:1px solid var(--msb-palette-border,#d0d3da);border-radius:7px;background:var(--msb-palette-surface,var(--msb-palette-bg,#fff));box-shadow:0 3px 12px rgba(15,23,42,.06)}
body article.post .post-head{min-height:68px;padding:12px 48px 10px 14px;display:flex;align-items:center;justify-content:flex-start;gap:10px;position:relative}
.community-post-avatar{width:46px;height:46px;flex:0 0 46px;border-radius:50%;display:grid;place-items:center;border:3px solid var(--msb-palette-action,#2563eb);background:var(--msb-palette-action-soft,#dbeafe);color:var(--msb-palette-action,#2563eb);font-weight:900}
.community-post-identity{display:flex;flex-direction:column;line-height:1.25}.community-post-identity strong{font-size:16px;color:var(--msb-palette-text,#0f172a)}.community-post-identity .muted{font-size:13px}
.community-post-name-row{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.community-post-member-badge{margin-left:auto;margin-right:4px;padding:3px 7px;border-radius:999px;background:var(--msb-palette-action-soft,#dbeafe);color:var(--msb-palette-action,#2563eb);font-size:11px;font-weight:800;flex:0 0 auto;align-self:center}.community-post-name-row .community-post-meta{display:inline-flex;align-items:center;gap:6px;margin:0;color:var(--msb-palette-text-muted,#64748b);font-size:13px;font-weight:500}
.community-post-fries{position:absolute;right:14px;top:17px;width:34px;height:34px;border:0;border-radius:50%;background:transparent;color:var(--msb-palette-text,#0f172a);display:grid;place-items:center;padding:0;cursor:pointer}.community-post-fries:hover{background:var(--msb-palette-hover-bg,var(--msb-palette-action-soft,#eef2f7))}.community-post-fries .pcm-fries-icon{display:inline-flex;flex-direction:column;justify-content:center;align-items:flex-start;gap:2px;width:18px;color:currentColor}.community-post-fries .pcm-fries-bar{display:block;width:18px;height:2px;border-radius:2px;background:currentColor}.community-post-fries .pcm-fries-bar--short{width:11px}
.community-post-menu{position:absolute;z-index:80;right:14px;top:56px;width:min(290px,calc(100% - 28px));padding:10px 16px;border:1px solid var(--msb-palette-border,#d8dee8);border-radius:16px;background:var(--msb-palette-surface,var(--msb-palette-bg,#fff));box-shadow:0 18px 45px rgba(15,23,42,.2);color:var(--msb-palette-text,#0f172a)}.community-post-menu[hidden]{display:none}.community-post-menu-group+.community-post-menu-group{margin-top:7px;padding-top:7px;border-top:1px solid var(--msb-palette-border,#d8dee8)}.community-post-menu button{width:100%;min-height:43px;display:flex;align-items:center;gap:14px;padding:8px 7px;border:0;border-radius:9px;background:transparent;color:inherit;text-align:left;font:inherit;font-size:16px;cursor:pointer}.community-post-menu button:hover,.community-post-menu button:focus-visible{background:var(--msb-palette-hover-bg,var(--msb-palette-action-soft,#eef2f7));outline:0}.community-post-menu button i{width:20px;color:var(--msb-palette-icon,var(--msb-palette-text,#0f172a));font-size:17px;text-align:center}.community-post-menu button.danger{color:#b42318}.community-post-menu button[hidden]{display:none}
body article.post>details{display:none!important}body article.post>details.community-edit-modal{position:fixed;inset:0;z-index:10020;padding:20px;background:rgba(0,0,0,.72);place-items:center}body article.post>details.community-edit-modal[open]{display:grid!important}body article.post>details.community-edit-modal>summary{display:none}body article.post>details.community-edit-modal>.edit-post{width:min(620px,100%);max-height:88vh;overflow:auto;padding:22px;border:1px solid var(--msb-palette-border,#d8dee8);border-radius:16px;background:var(--msb-palette-surface,var(--msb-palette-bg,#fff));box-shadow:0 24px 70px rgba(0,0,0,.35)}.community-edit-title{margin:0 0 14px;font-size:21px}.community-edit-close{margin-left:auto!important}
body article.post>h3{margin:2px 18px 8px;font-size:19px}body article.post>p{margin:8px 18px 14px}.post-media{display:block;width:auto;max-width:min(100%,720px);margin:12px 18px;max-height:min(56vh,calc(100dvh - 220px))!important;object-fit:contain!important;background:transparent;border-radius:8px!important}body article.post .community-post-media-stage .post-media,body article.post .community-post-media-clip .post-media{width:auto;max-width:min(100%,720px);margin:0;border-radius:8px!important}
body article.post>img.post-media[src$=".mp4" i],body article.post>img.post-media[src$=".webm" i],body article.post>img.post-media[src$=".mov" i],body article.post>img.post-media[src$=".m4v" i],body article.post>img.post-media[src$=".ogv" i],body article.post>img.post-media[src$=".ogg" i]{display:none!important}
.community-post-engagement.standard-text-actions{display:flex;align-items:center;justify-content:space-between;gap:16px;width:100%;margin-top:14px;padding:12px 15px 10px;border-top:1px solid var(--msb-palette-border,#d0d3da);box-sizing:border-box}.community-post-engagement .standard-text-left{display:flex;align-items:center;flex:1 1 auto;min-width:0}.community-post-engagement .standard-text-row{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.community-post-engagement .standard-text-right{display:flex;align-items:center;gap:18px;margin-left:auto;flex:0 0 auto}.community-post-engagement .msb-react-cluster{display:inline-flex;align-items:center;gap:6px}.community-post-engagement .standard-text-btn{display:inline-flex;align-items:center;gap:6px;padding:0;border:0;background:none;color:var(--msb-palette-text,#0f172a);font-size:14px;line-height:1;cursor:pointer}.community-post-engagement .standard-text-btn .msb-pact{font-size:18px;width:1.2em;height:1.2em;min-width:1.2em;min-height:1.2em;flex:0 0 1.2em}.community-post-engagement .action-count{font-size:12px;font-weight:600;color:var(--msb-palette-text,#0f172a);line-height:1;text-shadow:var(--msb-pact-contrast-text-shadow,none)}.community-post-engagement .standard-text-btn.is-loved,.community-post-engagement .standard-text-btn.is-loved .msb-pact{color:var(--msb-love-color,#ff4d6d)!important}.community-post-engagement .standard-text-btn.is-active,.community-post-engagement .standard-text-btn.is-active .msb-pact{color:var(--msb-palette-action,#2563eb)!important}.community-post-comments{display:none;padding:12px 15px 15px;border-top:1px solid var(--msb-palette-border,#d0d3da)}.community-post-comments.is-open{display:block}.community-comment-compose{display:flex;align-items:center;gap:9px}.community-comment-avatar{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:var(--msb-palette-action-soft,#dbeafe);color:var(--msb-palette-action,#2563eb);font-size:12px;font-weight:900}.community-comment-compose input{flex:1;height:40px;padding:0 14px;border:1px solid var(--msb-palette-border,#d0d3da);border-radius:999px;background:var(--msb-palette-input-bg,var(--msb-palette-surface-2,#f1f5f9));color:var(--msb-palette-text,#0f172a)}.community-comment-compose button{width:38px;height:38px;border:0;border-radius:50%;background:var(--msb-palette-action,#2563eb);color:var(--msb-palette-btn-text,#fff);cursor:pointer}.community-post-toast{position:fixed;left:50%;bottom:28px;z-index:10000;transform:translateX(-50%);padding:10px 16px;border-radius:999px;background:#111827;color:#fff;font-size:13px;font-weight:700;box-shadow:0 8px 24px rgba(0,0,0,.25)}
.community-action-confirm{position:fixed;inset:0;z-index:130000;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.58)}.community-action-confirm[hidden]{display:none}.community-action-confirm>section{position:relative;width:min(440px,calc(100vw - 32px));padding:26px 24px 22px;text-align:center;border:1px solid var(--msb-palette-border,#cbd5e1);border-radius:18px;background:var(--msb-palette-surface,#fff);color:var(--msb-palette-text,#0f172a);box-shadow:0 18px 48px rgba(0,0,0,.28)}.community-action-x{position:absolute;right:12px;top:10px;width:32px;height:32px;padding:0;border:0;border-radius:50%;background:transparent;color:var(--msb-palette-text-muted,#64748b);font-size:25px;line-height:30px;cursor:pointer}.community-action-icon{width:48px;height:48px;margin:0 auto 12px;border-radius:50%;display:grid;place-items:center;background:#fde8ec;color:#e52525;font-size:16px}.community-action-confirm h2{margin:0 0 8px;font-size:21px;line-height:1.25}.community-action-confirm p{margin:0 auto 20px;max-width:360px;color:var(--msb-palette-text-muted,#64748b);font-size:15px;line-height:1.4}.community-action-confirm section>div{display:grid;grid-template-columns:1fr 1fr;gap:10px}.community-action-confirm section>div button{height:42px;padding:0 16px;border-radius:999px;font-size:15px;font-weight:800;cursor:pointer}.community-action-cancel{border:1px solid var(--msb-palette-border,#cbd5e1);background:var(--msb-palette-surface,#fff);color:inherit}.community-action-submit{border:0;background:#e52525;color:#fff}.community-action-submit:disabled{opacity:.65;cursor:wait}
.community-feed-end{height:100px;min-height:100px;margin:0 -13px -40px;display:flex;align-items:center;justify-content:center;border:1px solid var(--msb-palette-border,#d8dee8);background:var(--msb-palette-bg,#171d24)!important;background-color:var(--msb-palette-bg,#171d24)!important;color:var(--msb-palette-text-muted,#64748b);font-size:12px;text-align:center}.community-feed-end span{padding:14px}@media(max-width:800px){.community-feed-end{height:88px;min-height:88px}}
body .content::-webkit-scrollbar{width:5px}body .content::-webkit-scrollbar-thumb{background:var(--msb-palette-text-muted,#94a3b8);border-radius:999px}
@media(max-width:1000px){body .layout{grid-template-columns:minmax(0,1fr) 330px}body .identity{padding-left:220px;padding-bottom:8px}body .avatar{width:160px;height:160px;top:-55px}body .avatar-edit{left:150px;top:38px}.identity-actions{position:static;margin-top:16px}}
@media(max-width:800px){body .layout{display:block}body .layout>div{display:block}body .hero,body .content,body .side{display:block}body .cover{left:auto;width:100%;transform:none;height:clamp(180px,32vw,260px)}body .identity::after{width:100%}body .tabs{width:100%;max-width:none;border-left:0;border-right:0;overflow-x:auto!important;overflow-y:hidden!important}body .tabs::before,body .tabs::after{display:none}body .side{position:static}body .identity{padding:88px 18px 10px;min-height:0}body .avatar{left:18px;top:-64px;width:140px;height:140px}body .avatar-edit{left:128px;top:18px}.side{margin-top:16px}.content{margin-top:16px}}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Calibri,Arial,sans-serif}body{background:var(--msb-palette-bg,#f5f7fb);color:var(--msb-palette-text,#0f172a)}.page{margin-left:var(--feed-rail-w,72px);padding:18px 24px 90px}.layout{max-width:1500px;margin:auto;display:grid;grid-template-columns:minmax(0,1fr) 350px;gap:20px}.main,.side-card,.composer,.post,.manage{border:1px solid var(--msb-palette-border,#d8dee8);background:var(--msb-palette-surface,var(--msb-palette-bg,#fff));border-radius:16px}.hero{overflow:hidden}.cover{height:290px;background:linear-gradient(135deg,var(--msb-palette-action,#2563eb),#0f766e) center/cover}.identity{position:relative;padding:16px 24px 12px 210px;min-height:125px}.avatar{position:absolute;left:28px;top:-82px;width:162px;height:162px;border-radius:50%;border:6px solid var(--msb-palette-bg,#fff);background:var(--msb-palette-action,#2563eb) center/cover;display:grid;place-items:center;color:#fff;font-size:64px;font-weight:900}.identity h1{margin:0 0 5px;font-size:31px}.muted{color:var(--msb-palette-text-muted,#64748b)}.identity-actions{position:absolute;right:22px;top:18px;display:flex;gap:9px}.btn{border:1px solid var(--msb-palette-border,#d8dee8);border-radius:10px;padding:10px 15px;background:var(--msb-palette-action-soft,#e8f1ff);color:var(--msb-palette-action,#2563eb);font-weight:800;text-decoration:none;cursor:pointer}.btn.primary{background:var(--msb-palette-action,#2563eb);color:var(--msb-palette-btn-text,#fff)}.tabs{display:flex;gap:22px;padding:0 24px;border-top:1px solid var(--msb-palette-border,#d8dee8);overflow:auto}.tabs a{padding:15px 4px;color:var(--msb-palette-text-muted,#64748b);font-weight:800;text-decoration:none;border-bottom:3px solid transparent}.tabs a.active{color:var(--msb-palette-action,#2563eb);border-color:var(--msb-palette-action,#2563eb)}.content{margin-top:16px}.composer,.post,.manage{padding:18px;margin-bottom:16px}.composer textarea,.composer input,.manage input,.manage textarea,.manage select,.edit-post input,.edit-post textarea{width:100%;padding:11px;margin:5px 0 10px;border:1px solid var(--msb-palette-border,#d8dee8);border-radius:9px;background:var(--msb-palette-input-bg,var(--msb-palette-bg,#fff));color:var(--msb-palette-text,#0f172a)}.post-head{display:flex;justify-content:space-between}.post h3{margin:10px 0 5px}.post-media{width:100%;max-height:650px;object-fit:cover;border-radius:12px}.tags{color:var(--msb-palette-action,#2563eb);font-weight:700}.post-actions{display:flex;gap:8px;margin-top:12px}.side{display:grid;gap:16px;align-content:start}.side-card{padding:20px}.side-card h2{margin:0 0 14px}.fact{display:flex;gap:12px;margin:13px 0}.fact i{width:20px;color:var(--msb-palette-action,#2563eb)}.rule{display:flex;gap:10px;align-items:center;margin:11px 0}.rule-number{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:var(--msb-palette-action-soft,#e8f1ff)}.member{display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid var(--msb-palette-border,#ddd)}.member-meta{display:inline-flex;align-items:center;gap:10px}.member-fries{width:34px;height:34px;border:0;border-radius:50%;background:transparent;color:var(--msb-palette-text,#0f172a);display:grid;place-items:center;cursor:pointer;padding:0}.member-fries:hover{background:var(--msb-palette-hover-bg,var(--msb-palette-action-soft,#eef2f7))}.member-fries .pcm-fries-icon{display:inline-flex;flex-direction:column;justify-content:center;align-items:flex-start;gap:2px;width:10px;color:currentColor}.member-fries .pcm-fries-bar{display:block;height:1.25px;border-radius:1px;background:currentColor;width:10px}.member-fries .pcm-fries-bar--short{width:6px}.members-search-wrap{position:relative;margin:0 0 14px}.members-search-wrap>i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--msb-palette-text-muted,#64748b);pointer-events:none}.members-search{width:100%;height:42px;padding:0 14px 0 40px;border:1px solid var(--msb-palette-border,#d8dee8);border-radius:10px;background:var(--msb-palette-input-bg,var(--msb-palette-bg,#fff));color:var(--msb-palette-text,#0f172a);font:inherit}.members-search:focus{outline:2px solid var(--msb-palette-action,#2563eb);outline-offset:1px}.members-search-empty{margin:8px 0 0;padding:10px 4px;text-align:center}.alert{padding:12px;margin:0 0 15px;border-radius:10px;background:var(--msb-palette-action-soft,#dbeafe)}.alert.error{background:#fee2e2;color:#991b1b}.danger{border-color:#dc2626}.danger .btn{background:#dc2626;color:#fff}.modal{position:fixed;inset:0;background:rgba(0,0,0,.7);display:grid;place-items:center;padding:20px;z-index:9999}.modal[hidden]{display:none}.modal-box{width:min(650px,100%);max-height:90vh;overflow:auto;padding:22px;border-radius:16px;background:var(--msb-palette-bg,#fff)}@media(max-width:950px){.layout{grid-template-columns:1fr}.side{grid-row:2}.identity{padding-left:145px}.avatar{width:110px;height:110px;top:-45px}.identity-actions{position:static;margin-top:14px}.cover{height:220px}}@media(max-width:650px){.page{margin-left:0;padding:10px 10px 80px}.identity{padding:70px 15px 15px}.avatar{left:15px}.tabs{gap:14px;padding:0 12px}}
.community-top-search{position:relative;width:min(650px,70%);margin:0 auto 14px}.community-top-search i{position:absolute;left:15px;top:50%;transform:translateY(-50%);color:var(--msb-palette-text-muted,#64748b)}.community-top-search input{width:100%;height:42px;border:0;border-radius:999px;padding:0 18px 0 42px;background:var(--msb-palette-input-bg,var(--msb-palette-surface-2,#eef3fb));color:var(--msb-palette-text,#0f172a)}.hero{border-radius:14px}.cover{height:255px;position:relative}.cover-edit{position:absolute;right:18px;bottom:16px;background:rgba(15,23,42,.72);color:#fff}.identity{padding:18px 300px 14px 190px;min-height:180px}.avatar{left:22px;top:-70px;width:148px;height:148px;border-width:5px}.avatar-edit{position:absolute;left:228px;top:62px;width:44px;height:44px;border:3px solid var(--msb-palette-bg,#fff);border-radius:50%;background:var(--msb-palette-surface-2,#fff);color:var(--msb-palette-text,#0f172a);display:grid;place-items:center;z-index:2}.identity p{margin:10px 0 8px;line-height:1.35}.community-tags{display:flex;gap:7px;flex-wrap:wrap}.community-tags span{padding:6px 11px;border-radius:999px;background:var(--msb-palette-action-soft,#e8f1ff);color:var(--msb-palette-action,#2563eb);font-size:13px;font-weight:800}.tabs{justify-content:flex-start;gap:42px}.tabs a i{margin-right:7px}.composer.compact{padding:14px 18px}.composer.compact summary{list-style:none;cursor:pointer}.composer.compact summary::-webkit-details-marker{display:none}.composer-prompt{display:flex;align-items:center;gap:12px}.composer-mini-avatar{width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:var(--msb-palette-action,#2563eb);color:#fff;font-weight:900}.composer-placeholder{flex:1;padding:13px 16px;border-radius:999px;background:var(--msb-palette-input-bg,var(--msb-palette-surface-2,#eef3fb));color:var(--msb-palette-text-muted,#64748b)}.composer-tools{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:13px 4px 2px;color:var(--msb-palette-text-muted,#64748b);font-weight:800}.composer-tools span i{margin-right:6px;color:var(--msb-palette-action,#2563eb)}.composer-fields{padding-top:14px}.feed-filter{display:flex;align-items:center;gap:28px;padding:12px 18px;margin-bottom:12px;border-bottom:1px solid var(--msb-palette-border,#ddd);font-weight:800;color:var(--msb-palette-text-muted,#64748b)}.feed-filter .active{color:var(--msb-palette-action,#2563eb)}.feed-filter .sort{margin-left:auto}.side-card{border-radius:14px;box-shadow:0 5px 18px rgba(15,23,42,.04)}.side-card-head{display:flex;justify-content:space-between;align-items:center}.side-card-head button{border:0;background:transparent;color:var(--msb-palette-action,#2563eb);font-weight:800}.upcoming{display:flex;gap:12px;align-items:center}.event-date{width:58px;height:58px;border-radius:10px;background:var(--msb-palette-action-soft,#e8f1ff);display:grid;place-items:center;color:var(--msb-palette-action,#2563eb);font-weight:900}.post{box-shadow:0 4px 16px rgba(15,23,42,.04)}@media(max-width:1100px){.identity{padding-right:22px}.identity-actions{position:static;margin-top:14px}.tabs{gap:22px}}@media(max-width:650px){.community-top-search{width:100%}.identity{padding:88px 15px 15px}.avatar-edit{left:108px;top:30px}.composer-tools{justify-content:flex-start}.cover{height:190px}}
.community-invite-search{width:100%;height:42px;padding:0 14px;margin:0 0 12px;border:1px solid var(--msb-palette-border,#d8dee8);border-radius:10px;background:var(--msb-palette-input-bg,var(--msb-palette-bg,#fff));color:var(--msb-palette-text,#0f172a)}.community-invite-list{display:grid;gap:8px;max-height:min(360px,50vh);overflow:auto;padding:2px 2px 8px 0;margin:0 0 14px}.community-invite-option{display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid var(--msb-palette-border,#d8dee8);border-radius:12px;background:var(--msb-palette-surface,var(--msb-palette-bg,#fff));cursor:pointer}.community-invite-option:hover{background:var(--msb-palette-hover-bg,var(--msb-palette-surface-2,#f3f4f6))}.community-invite-option input{width:18px;height:18px;accent-color:var(--msb-palette-action,#2563eb)}.community-invite-avatar{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:var(--msb-palette-action-soft,#e8f1ff);color:var(--msb-palette-action,#2563eb);font-weight:900;font-size:13px;flex:0 0 36px}.community-invite-name{font-weight:800;color:var(--msb-palette-text,#0f172a)}.community-invite-empty{padding:18px 8px;color:var(--msb-palette-text-muted,#64748b);text-align:center}.community-invite-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}

/* Scale down text / borders / buttons / icons only — keep layout */
body.community-profile-page{
  font-size:13.5px;
}
body.community-profile-page .identity h1{font-size:28px!important;line-height:1.15}
body.community-profile-page .identity>.muted,
body.community-profile-page .identity p{font-size:13.5px!important;line-height:1.4}
body.community-profile-page .community-tags span{
  padding:4px 9px;font-size:11.5px;font-weight:700;border-radius:999px;
}
body.community-profile-page .tabs a{
  font-size:13.5px;font-weight:400!important;padding:12px 14px 10px;
}
body.community-profile-page .tabs a.active{font-weight:500!important}
body.community-profile-page .tabs a i{font-size:13px;margin-right:5px}
/* Clean full-width tabs divider — one line across left + right */
body.community-profile-page .identity::after{
  display:block!important;
  content:""!important;
  position:absolute;
  left:0;right:0;bottom:0;
  width:100%!important;
  max-width:none;
  height:1px;
  transform:none!important;
  background:var(--msb-palette-border,#d8dee8);
  pointer-events:none;
}
body.community-profile-page .hero{
  border:0!important;
  border-bottom:0!important;
  overflow:visible!important;
}
body.community-profile-page .tabs{
  width:100%!important;
  max-width:none!important;
  border:0!important;
  border-bottom:1px solid var(--msb-palette-border,#d8dee8)!important;
  box-sizing:border-box;
}
body.community-profile-page .tabs::before,
body.community-profile-page .tabs::after{display:none!important;content:none!important}
/* Sidebar starts under the tabs line (no overlap / gap border) */
body.community-profile-page .side{
  margin-top:16px!important;
}
body.community-profile-page .content{
  border-left:1px solid var(--msb-palette-border,#d8dee8)!important;
  border-right:1px solid var(--msb-palette-border,#d8dee8)!important;
}
body.community-profile-page .btn{
  padding:7px 12px;border-radius:8px;font-size:12.5px;font-weight:700;
  border-width:1px;
}
body.community-profile-page .btn i{font-size:12px}
body.community-profile-page .side-card{
  padding:14px 14px;border:1px solid #e5e7eb;border-radius:10px;
  box-shadow:0 1px 2px rgba(15,23,42,.04);
}
body.community-profile-page .side-card h2{font-size:15px;margin:0 0 10px;font-weight:800}
body.community-profile-page .side-card-head button{font-size:12.5px;font-weight:700}
body.community-profile-page .side-card p,
body.community-profile-page .fact{font-size:12.5px;line-height:1.35}
body.community-profile-page .fact{gap:9px;margin:9px 0}
body.community-profile-page .fact i{font-size:13px;width:16px}
body.community-profile-page .rule{gap:8px;margin:8px 0;font-size:12.5px}
body.community-profile-page .rule-number{width:24px;height:24px;font-size:11px;font-weight:700}
body.community-profile-page .side-card .rule>form button{
  width:28px;height:28px;border-radius:7px;font-size:15px;
}
body.community-profile-page .side-card form:has(input[name="rule"]) input[name="rule"]{
  height:34px;padding:0 10px;border-radius:8px;font-size:12.5px;border-width:1px;
}
body.community-profile-page .side-card form:has(input[name="rule"]) .btn{
  height:34px;padding:0 12px;font-size:12px;
}
body.community-profile-page .member{padding:9px 6px;font-size:12.5px}
body.community-profile-page .member-fries{width:28px;height:28px}
body.community-profile-page .member-fries .pcm-fries-bar{height:1.5px}
body.community-profile-page .members-search{
  height:34px;border-radius:8px;font-size:12.5px;border-width:1px;
}
body.community-profile-page .members-search-wrap>i{font-size:13px}
body.community-profile-page .composer.compact,
body.community-profile-page .composer{
  padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;
}
body.community-profile-page .composer-mini-avatar{width:38px;height:38px;font-size:13px}
body.community-profile-page .composer-placeholder{font-size:13.5px;padding:10px 14px}
body.community-profile-page .composer-tools{font-size:12.5px;font-weight:650;padding:10px 4px 0}
body.community-profile-page .composer-tools span i{font-size:13px;margin-right:5px}
body.community-profile-page .composer textarea,
body.community-profile-page .composer input,
body.community-profile-page .manage input,
body.community-profile-page .manage textarea,
body.community-profile-page .manage select{
  padding:9px 11px;border-radius:8px;font-size:13px;border-width:1px;
}
body.community-profile-page .manage{padding:14px;border:1px solid #e5e7eb;border-radius:10px}
body.community-profile-page .manage h2{font-size:16px}
body.community-profile-page .feed-filter,
body.community-profile-page .feed-filter span,
body.community-profile-page .feed-filter .sort,
body.community-profile-page .feed-filter .active{
  font-weight:400!important;
}
body.community-profile-page .feed-filter:before{font-size:16px;font-weight:400!important}
body.community-profile-page .feed-filter .sort{font-size:12px;padding:6px 10px;border-radius:6px}
body.community-profile-page article.post{
  border:1px solid #e5e7eb;border-radius:8px;
  box-shadow:0 1px 2px rgba(15,23,42,.04);
}
body.community-profile-page article.post .post-head{
  min-height:0;padding:10px 42px 8px 12px;font-size:13px;
}
body.community-profile-page .community-post-avatar{
  width:38px;height:38px;flex-basis:38px;border-width:2px;font-size:13px;
}
body.community-profile-page .community-post-identity strong{font-size:13.5px}
body.community-profile-page .community-post-identity .muted,
body.community-profile-page .community-post-name-row .community-post-meta{font-size:11.5px}
body.community-profile-page .community-post-member-badge{
  padding:2px 6px;font-size:10px;font-weight:700;
}
body.community-profile-page .community-post-fries{width:28px;height:28px;right:10px;top:12px}
body.community-profile-page .community-post-fries .pcm-fries-icon{width:14px;gap:2px}
body.community-profile-page .community-post-fries .pcm-fries-bar{width:14px;height:1.5px}
body.community-profile-page .community-post-fries .pcm-fries-bar--short{width:9px}
body.community-profile-page article.post>h3{font-size:15.5px;margin:2px 14px 6px}
body.community-profile-page article.post>p{font-size:13.5px;line-height:1.4;margin:6px 14px 10px}
body.community-profile-page .tags{font-size:12px}
body.community-profile-page .community-post-engagement.standard-text-actions{
  gap:12px;margin-top:10px;padding:8px 12px 8px;border-top-width:1px;
}
body.community-profile-page .community-post-engagement .standard-text-row{gap:14px}
body.community-profile-page .community-post-engagement .standard-text-right{gap:14px}
body.community-profile-page .community-post-engagement .standard-text-btn{font-size:12.5px;gap:5px}
body.community-profile-page .community-post-engagement .standard-text-btn .msb-pact{
  font-size:15px;width:1.1em;height:1.1em;min-width:1.1em;min-height:1.1em;flex-basis:1.1em;
}
body.community-profile-page .community-post-engagement .action-count{font-size:11px}
body.community-profile-page .community-comment-avatar{width:28px;height:28px;font-size:11px}
body.community-profile-page .community-comment-compose input{height:34px;font-size:12.5px;border-width:1px}
body.community-profile-page .community-comment-compose button{width:32px;height:32px;font-size:12px}
body.community-profile-page .community-post-menu{
  padding:8px 10px;border-radius:10px;border-width:1px;
}
body.community-profile-page .community-post-menu button{
  min-height:36px;gap:10px;padding:6px;font-size:13.5px;border-radius:7px;
}
body.community-profile-page .community-post-menu button i{width:16px;font-size:14px}
body.community-profile-page .community-top-search input{
  height:36px;font-size:13px;border:1px solid #e2e8f0;border-radius:8px;
}
body.community-profile-page .community-top-search i{font-size:13px}
body.community-profile-page .upcoming .event-date{
  width:48px;height:48px;font-size:12px;border-radius:8px;
}
body.community-profile-page .community-invite-search{
  height:34px;font-size:12.5px;border-radius:8px;border-width:1px;
}
body.community-profile-page .community-invite-option{
  padding:8px 10px;border-radius:8px;border-width:1px;gap:10px;
}
body.community-profile-page .community-invite-avatar{width:30px;height:30px;flex-basis:30px;font-size:11px}
body.community-profile-page .community-invite-name{font-size:13px}
body.community-profile-page .alert{padding:9px 11px;border-radius:8px;font-size:12.5px}
body.community-profile-page .community-feed-end{font-size:11.5px}
</style></head><body class="community-profile-page community-tab-<?=h($tab)?>"><?php $skipHeaderThemeBootstrap=true;include __DIR__.'/includes/header.php';?><style id="community-feed-filter-bg">
/* Day (Dark auto off / daytime): feed column + Posts bar share #171d24 */
html:not([data-msb-appearance]):not(.dark-auto) body .content,
html:not([data-msb-appearance]):not(.dark-auto) body .community-feed-end,
html:not([data-msb-appearance]):not(.dark-auto) body .content>.feed-filter,
html:not([data-msb-appearance]):not(.dark-auto) body .content .feed-filter,
html:not([data-msb-appearance]):not(.dark-auto) body .content .feed-filter span,
html:not([data-msb-appearance]):not(.dark-auto) body .content .feed-filter span.active,
html:not([data-msb-appearance]):not(.dark-auto) body .content .feed-filter .sort{
  /* background:#171d24!important;
  background-color:#171d24!important; */
  background-image:none!important;
}
/* Night: surrounding feed column is pure black; Posts bar stays #171d24 */
html.dark-auto:not([data-msb-appearance]) body .content,
html.dark-auto:not([data-msb-appearance]) body .community-feed-end,
html:not([data-msb-appearance]) body.dark-auto .content,
html:not([data-msb-appearance]) body.dark-auto .community-feed-end,
html[data-theme="dark"]:not([data-msb-appearance]) body .content,
html[data-theme="dark"]:not([data-msb-appearance]) body .community-feed-end{
  background:#000000!important;
  background-color:#000000!important;
}
html.dark-auto:not([data-msb-appearance]) body .content>.feed-filter,
html.dark-auto:not([data-msb-appearance]) body .content .feed-filter,
html.dark-auto:not([data-msb-appearance]) body .content .feed-filter span,
html.dark-auto:not([data-msb-appearance]) body .content .feed-filter span.active,
html.dark-auto:not([data-msb-appearance]) body .content .feed-filter .sort,
html:not([data-msb-appearance]) body.dark-auto .content>.feed-filter,
html:not([data-msb-appearance]) body.dark-auto .content .feed-filter,
html:not([data-msb-appearance]) body.dark-auto .content .feed-filter span,
html:not([data-msb-appearance]) body.dark-auto .content .feed-filter span.active,
html:not([data-msb-appearance]) body.dark-auto .content .feed-filter .sort,
html[data-theme="dark"]:not([data-msb-appearance]) body .content>.feed-filter,
html[data-theme="dark"]:not([data-msb-appearance]) body .content .feed-filter,
html[data-theme="dark"]:not([data-msb-appearance]) body .content .feed-filter span,
html[data-theme="dark"]:not([data-msb-appearance]) body .content .feed-filter span.active,
html[data-theme="dark"]:not([data-msb-appearance]) body .content .feed-filter .sort{
  background:#171d24!important;
  background-color:#171d24!important;
  background-image:none!important;
}
/* Appearance / Progress color: follow palette */
html[data-msb-appearance] body .content,
html[data-msb-appearance] body .community-feed-end,
html[data-msb-appearance] body .content>.feed-filter,
html[data-msb-appearance] body .content .feed-filter,
html[data-msb-appearance] body .content .feed-filter span,
html[data-msb-appearance] body .content .feed-filter span.active,
html[data-msb-appearance] body .content .feed-filter .sort{
  background:var(--msb-palette-bg)!important;
  background-color:var(--msb-palette-bg)!important;
  background-image:none!important;
}
html body .content .feed-filter span.active{border-bottom:3px solid var(--msb-palette-action,#2374e1)!important}
html[data-msb-appearance] body:not(.org-app) .avatar.has-photo,
html.dark-auto body .avatar.has-photo,
body.dark-auto .avatar.has-photo,
html body .avatar.has-photo{
  background:none!important;
  background-image:none!important;
  background-color:transparent!important;
}
html body .avatar.has-photo img{
  width:100%!important;
  height:100%!important;
  object-fit:cover!important;
  border-radius:50%!important;
  display:block!important;
}
/* Match profile.php: the Home/Posts canvas is light blue by day. This remains
   independent of Appearance/Progress colors and updates as Dark Auto changes. */
html:not(.dark-auto):not([data-theme="dark"]) body:not(.dark-auto).community-profile-page.community-tab-home .content,
html:not(.dark-auto):not([data-theme="dark"]) body:not(.dark-auto).community-profile-page.community-tab-posts .content,
html:not(.dark-auto):not([data-theme="dark"]) body:not(.dark-auto).community-profile-page.community-tab-home .community-feed-end,
html:not(.dark-auto):not([data-theme="dark"]) body:not(.dark-auto).community-profile-page.community-tab-posts .community-feed-end{
  background:#e5eff6!important;
  background-color:#e5eff6!important;
  background-image:none!important;
}
/* At night, Dark Auto/manual dark always wins—even while Appearance or
   Progress color preview attributes are present on <html>. */
html.dark-auto body.community-profile-page.community-tab-home .content,
html.dark-auto body.community-profile-page.community-tab-posts .content,
html.dark-auto body.community-profile-page.community-tab-home .community-feed-end,
html.dark-auto body.community-profile-page.community-tab-posts .community-feed-end,
html body.dark-auto.community-profile-page.community-tab-home .content,
html body.dark-auto.community-profile-page.community-tab-posts .content,
html body.dark-auto.community-profile-page.community-tab-home .community-feed-end,
html body.dark-auto.community-profile-page.community-tab-posts .community-feed-end,
html[data-theme="dark"] body.community-profile-page.community-tab-home .content,
html[data-theme="dark"] body.community-profile-page.community-tab-posts .content,
html[data-theme="dark"] body.community-profile-page.community-tab-home .community-feed-end,
html[data-theme="dark"] body.community-profile-page.community-tab-posts .community-feed-end{
  background:#000000!important;
  background-color:#000000!important;
  background-image:none!important;
}
/* The Posts filter follows Appearance; its active progress uses Progress color. */
html body.community-profile-page.community-tab-home .feed-filter,
html body.community-profile-page.community-tab-home .feed-filter span,
html body.community-profile-page.community-tab-home .feed-filter span.active,
html body.community-profile-page.community-tab-home .feed-filter .sort,
html body.community-profile-page.community-tab-posts .feed-filter,
html body.community-profile-page.community-tab-posts .feed-filter span,
html body.community-profile-page.community-tab-posts .feed-filter span.active,
html body.community-profile-page.community-tab-posts .feed-filter .sort{
  background:var(--msb-palette-surface,var(--msb-palette-bg,#e5eff6))!important;
  background-color:var(--msb-palette-surface,var(--msb-palette-bg,#e5eff6))!important;
  background-image:none!important;
}
html body.community-profile-page.community-tab-home .feed-filter span.active,
html body.community-profile-page.community-tab-posts .feed-filter span.active{
  border-bottom-color:var(--msb-palette-action,#2374e1)!important;
}
.member-role-form{display:inline-flex;align-items:center;gap:7px;margin:0}.member-role-form select{height:34px;border:1px solid var(--msb-palette-border,#d8dee8);border-radius:8px;background:var(--msb-palette-input-bg,var(--msb-palette-bg,#fff));color:var(--msb-palette-text,#0f172a);padding:0 8px}.member-role-form button{height:34px;padding:0 10px;border:1px solid var(--msb-palette-border,#d8dee8);border-radius:8px;background:var(--msb-palette-action-soft,#e8f1ff);color:var(--msb-palette-action,#2563eb);font-weight:800;cursor:pointer}
.join-requests{margin:0 0 18px;padding:14px;border:1px solid var(--msb-palette-border,#d8dee8);border-radius:12px;background:var(--msb-palette-surface-2,var(--msb-palette-bg,#fff))}.join-requests h3{margin:0 0 10px}.join-request{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid var(--msb-palette-border,#d8dee8)}.join-request:first-of-type{border-top:0}.join-request-actions{display:flex;gap:8px;margin:0}.join-request-actions button{border:1px solid var(--msb-palette-border,#d8dee8);border-radius:8px;padding:8px 12px;font-weight:800;cursor:pointer}.join-request-actions .accept{background:var(--msb-palette-action,#2563eb);color:#fff}.join-request-actions .deny{background:transparent;color:var(--msb-palette-text,#0f172a)}
.community-post-copy{margin:8px 18px 14px;font-size:16px;line-height:1.45;color:var(--msb-palette-text,#0f172a)}
.community-post-copy-short{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:5;line-clamp:5;overflow:hidden}
.community-post-readmore{display:none;border:0;padding:0;background:transparent;color:inherit;font:inherit;font-weight:800;cursor:pointer}
.community-post-copy.has-overflow .community-post-readmore{display:inline}
.community-post-readmore[hidden]{display:none}
.community-post-readmore:hover,.community-post-readmore:focus-visible{text-decoration:underline}
.community-post-copy[data-expanded="1"] .community-post-copy-short{display:none}
</style><main class="page"><form class="community-top-search" action="community.php"><i class="fa fa-search"></i><input name="q" placeholder="Search posts, people, or communities..."></form><div class="layout"><div><section class="main hero"><div class="cover"<?=$cover!==''?' style="background-image:url(\''.h($cover).'\')"':''?>><?php if($isOwner):?><button class="btn cover-edit" type="button" data-open="manageModal"><i class="fa fa-camera"></i> Edit Cover</button><?php endif;?></div><div class="identity"><div class="avatar<?=$logo!==''?' has-photo':''?>"><?php if($logo!==''):?><img src="<?=h($logo)?>" alt="<?=h((string)$community['name'])?> logo"><?php else:?><?=h($initial)?><?php endif;?></div><?php if($isOwner):?><button class="avatar-edit" type="button" data-open="manageModal" aria-label="Edit community image"><i class="fa fa-camera"></i></button><?php endif;?><h1><?=h((string)$community['name'])?></h1><div class="muted"><i class="fa fa-globe"></i> <?=h(ucfirst((string)$community['privacy']))?> community · <i class="fa fa-map-marker"></i> <?=h((string)$community['location_name'])?> · <i class="fa fa-users"></i> <?=(int)$community['member_count']?> member<?=((int)$community['member_count']===1?'':'s')?></div><div class="community-tags"><span>#<?=h(str_replace(' ','',(string)$community['category']))?></span><span>#Community</span><span>#<?=h(str_replace([' ', ','],'',(string)$community['location_name']))?></span></div><div class="identity-actions"><?php if($isMember):?><?php if($isOwner):?><button class="btn" type="button" disabled title="Owners cannot leave">✓ Joined</button><?php else:?><button class="btn" type="button" id="communityJoinedBtn" data-open="leaveModal">✓ Joined</button><form method="post" id="communityLeaveForm" hidden><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><input type="hidden" name="action" value="leave"></form><?php endif;?><button class="btn" type="button" data-open="inviteModal"><i class="fa fa-user-plus"></i> Invite</button><?php elseif($memberStatus==='pending'):?><button class="btn" disabled>Requested</button><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><button class="btn primary" name="action" value="join">Join Community</button></form><?php endif;?><?php if($isOwner):?><button class="btn primary" type="button" data-open="manageModal"><i class="fa fa-cog"></i> Manage</button><?php endif;?><?php if($isMember):?><a class="btn" href="messages.php?chat_type=group&amp;community_id=<?=$communityId?>"><i class="fa fa-commenting-o"></i> Message</a><?php endif;?><button class="btn" type="button">•••</button></div></div><nav class="tabs"><?php $tabIcons=['home'=>'home','posts'=>'commenting-o','media'=>'picture-o','members'=>'users','events'=>'calendar','about'=>'bell-o'];$tabs=['home'=>'Home','posts'=>'Posts','media'=>'Media'];if($isMember)$tabs['members']='Members';$tabs['events']='Events';$tabs['about']='About';foreach($tabs as$k=>$v):?><a class="<?=$tab===$k?'active':''?>" href="community_profile.php?id=<?=$communityId?>&amp;tab=<?=$k?>"><i class="fa fa-<?=$tabIcons[$k]?>"></i><?=$v?></a><?php endforeach;?></nav></section><div class="content"><?php if($notice):?><div class="alert"><?=h($notice)?></div><?php endif;?><?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?>
<?php if(in_array($tab,['home','posts'],true)):?><?php if($isMember):?><details class="composer compact"><summary><div class="composer-prompt"><span class="composer-mini-avatar"><?=h($initial)?></span><span class="composer-placeholder">Share something with this community...</span></div><div class="composer-tools"><span><i class="fa fa-camera"></i>Photo/Video</span><span><i class="fa fa-user-plus"></i>Tag People</span><span><i class="fa fa-bar-chart"></i>Poll</span><span><i class="fa fa-calendar"></i>Event</span><span><i class="fa fa-map-marker"></i>Location</span><span><i class="fa fa-smile-o"></i>Feeling/Activity</span><span><i class="fa fa-ellipsis-h"></i>More</span></div></summary><form class="composer-fields" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><input name="title" placeholder="Title or subject (optional)"><textarea name="body" rows="3" placeholder="Share something with this community..."></textarea><input name="hashtags" placeholder="#Local #Support #Garland"><input type="file" name="post_media" accept="image/*"><button class="btn primary" name="action" value="create_post">Post</button></form></details><?php endif;?><div class="feed-filter"><span class="active">Latest</span><span>Popular</span><span>Media</span><span>Events</span><span class="sort"><i class="fa fa-sort-amount-desc"></i> Newest first</span></div><?php foreach($posts as$p):?><?php $bodyClamp=cp_post_body_clamp((string)$p['body']);?><article class="post"><header class="post-head"><strong><?=h((string)$p['display_name'])?></strong><span class="muted"><?=h(date('M j, Y',strtotime((string)$p['created_at'])))?><?=$p['status']==='pending'?' · Pending approval':''?></span></header><?php if($p['title']!==''):?><h3><?=h((string)$p['title'])?></h3><?php endif;?><?php if($bodyClamp['full']!==''):?><div class="community-post-copy" data-expanded="0"><span class="community-post-copy-short"><?=nl2br(h((string)$bodyClamp['short']))?></span><?php if($bodyClamp['clamped']):?><span class="community-post-copy-full" hidden><?=nl2br(h((string)$bodyClamp['full']))?></span> <button type="button" class="community-post-readmore" aria-expanded="false">Read more</button><?php endif;?></div><?php endif;?><?php if($p['media_path']!==''):?><img class="post-media" src="<?=h((string)$p['media_path'])?>" alt="Community post media"><?php endif;?><?php if($p['hashtags']!==''):?><p class="tags"><?=h((string)$p['hashtags'])?></p><?php endif;?><?php if((int)$p['user_id']===$meId||$canManagePosts):?><details><summary class="btn">Edit post</summary><form class="edit-post" method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><input type="hidden" name="post_id" value="<?=(int)$p['id']?>"><input name="title" value="<?=h((string)$p['title'])?>"><textarea name="body" rows="4"><?=h((string)$p['body'])?></textarea><input name="hashtags" value="<?=h((string)$p['hashtags'])?>"><div class="post-actions"><button class="btn primary" name="action" value="save_post">Save</button><button class="btn" name="action" value="delete_post" onclick="return confirm('Delete this post?')">Delete</button></div></form></details><?php endif;?></article><?php endforeach;?><?php elseif($tab==='media'):?><section class="post"><h2>Media</h2><?php foreach($posts as$p):?><?php if($p['media_path']!==''):?><img class="post-media" src="<?=h((string)$p['media_path'])?>" alt="Community media"><?php endif;?><?php endforeach;?></section><?php elseif($tab==='members'):?><section class="post members-panel"><?php if($isStaff):?><div class="join-requests"><h3>Join requests<?=count($pendingRequests)>0?' ('.count($pendingRequests).')':''?></h3><?php if($pendingRequests):?><?php foreach($pendingRequests as$request):?><div class="join-request"><div><strong><?=h((string)$request['display_name'])?></strong><br><small class="muted">Requested <?=h(date('M j, Y',strtotime((string)$request['created_at'])))?></small></div><form class="join-request-actions" method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><input type="hidden" name="member_user_id" value="<?=(int)$request['user_id']?>"><button class="accept" name="action" value="approve_join_request">Accept</button><button class="deny" name="action" value="deny_join_request">Deny</button></form></div><?php endforeach;?><?php else:?><p class="muted">No pending requests.</p><?php endif;?></div><?php endif;?><div class="members-search-wrap"><i class="fa fa-search" aria-hidden="true"></i><input type="search" id="communityMembersSearch" class="members-search" placeholder="Search members..." autocomplete="off" aria-label="Search members"></div><div id="communityMembersList"><?php foreach($members as$m):?><div class="member" data-name="<?=h(mb_strtolower((string)$m['display_name']))?>"><strong><?=h((string)$m['display_name'])?></strong><div class="member-meta"><span><?=h(ucfirst((string)$m['role']))?></span><?php if($canAssignManagers&&in_array((string)$m['role'],['member','manager'],true)):?><form class="member-role-form" method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><input type="hidden" name="member_user_id" value="<?=(int)$m['user_id']?>"><select name="member_role" aria-label="Role for <?=h((string)$m['display_name'])?>"><option value="member"<?=$m['role']==='member'?' selected':''?>>Member</option><option value="manager"<?=$m['role']==='manager'?' selected':''?>>Manager</option></select><button name="action" value="set_member_role">Save</button></form><?php endif;?><button type="button" class="member-fries" aria-label="Member options"><span class="pcm-fries-icon" aria-hidden="true"><span class="pcm-fries-bar"></span><span class="pcm-fries-bar pcm-fries-bar--short"></span><span class="pcm-fries-bar"></span><span class="pcm-fries-bar pcm-fries-bar--short"></span></span></button></div></div><?php endforeach;?></div><p class="members-search-empty muted" id="communityMembersEmpty" hidden>No members match that name.</p></section><?php elseif($tab==='events'):?><section class="post"><h2>Local Events</h2><p class="muted">Community event CRUD will appear here when events are added.</p></section><?php else:?><section class="post"><h2>About</h2><p><?=nl2br(h((string)$community['description']))?></p><p><strong>Location:</strong> <?=h((string)$community['location_name'])?></p><p><strong>Purpose:</strong> <?=h((string)$community['category'])?></p></section><?php endif;?></div></div>
<aside class="side"><section class="side-card"><div class="side-card-head"><h2>About</h2><?php if($isOwner):?><button type="button" data-open="manageModal">Edit</button><?php endif;?></div><p><?=h((string)$community['description'])?></p><div class="fact"><i class="fa fa-globe"></i><div><strong><?=h(ucfirst((string)$community['privacy']))?> community</strong><br><small class="muted"><?=($community['privacy']==='public'?'Anyone can find and join this community.':'Only members can view community content.')?></small></div></div><div class="fact"><i class="fa fa-users"></i><strong><?=(int)$community['member_count']?> member<?=((int)$community['member_count']===1?'':'s')?></strong></div><div class="fact"><i class="fa fa-map-marker"></i><strong><?=h((string)$community['location_name'])?></strong></div><div class="fact"><i class="fa fa-calendar"></i><strong>Created <?=h(date('M j, Y',strtotime((string)$community['created_at'])))?></strong></div><div class="fact"><i class="fa fa-tag"></i><strong><?=h((string)$community['category'])?></strong></div><div class="fact"><i class="fa fa-link"></i><span class="muted">Add website</span></div></section><section class="side-card"><div class="side-card-head"><h2>Community Rules</h2><button type="button">See all</button></div><?php foreach($rules as$i=>$r):?><div class="rule"><span class="rule-number"><?=$i+1?></span><span><?=h((string)$r['rule_text'])?></span><?php if($isStaff):?><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><input type="hidden" name="rule_id" value="<?=(int)$r['id']?>"><button name="action" value="delete_rule" title="Delete rule">×</button></form><?php endif;?></div><?php endforeach;?><?php if($isStaff):?><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><input name="rule" placeholder="Add a community rule" required><button class="btn" style="margin-left:10px" name="action" value="add_rule">Add rule</button></form><?php endif;?></section><section class="side-card"><div class="side-card-head"><h2>Upcoming Events</h2><button type="button">See all</button></div><div class="upcoming"><span class="event-date"><i class="fa fa-calendar"></i></span><div><strong>Community Meeting</strong><br><small class="muted">Plan your first local event<br><i class="fa fa-map-marker"></i> <?=h((string)$community['location_name'])?></small></div><a class="btn" href="community_profile.php?id=<?=$communityId?>&amp;tab=events">View</a></div></section></aside></div></main>
<?php if($isOwner):?><div class="modal" id="manageModal" hidden><section class="modal-box"><button class="btn" type="button" data-close="manageModal">Close</button><form class="manage" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><h2>Edit Community</h2><label>Name<input name="name" value="<?=h((string)$community['name'])?>" required></label><label>City and state<input name="location_name" value="<?=h((string)$community['location_name'])?>" required></label><label>Description<textarea name="description" rows="4"><?=h((string)$community['description'])?></textarea></label><label>Purpose<input name="category" value="<?=h((string)$community['category'])?>"></label><label>Privacy<select name="privacy"><option value="public"<?=$community['privacy']==='public'?' selected':''?>>Public</option><option value="private"<?=$community['privacy']==='private'?' selected':''?>>Private</option></select></label><label>Who can join?<select name="join_policy"><option value="anyone"<?=($community['join_policy']??'anyone')==='anyone'?' selected':''?>>Anyone</option><option value="approval"<?=($community['join_policy']??'')==='approval'?' selected':''?>>Admin approval required</option></select></label><label>New logo<input type="file" name="profile_image" accept="image/*"></label><label>New cover<input type="file" name="cover_image" accept="image/*"></label><button class="btn primary" name="action" value="save_community">Save Community</button></form><form class="manage danger" method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><h2>Danger Zone</h2><p>Enter <strong><?=h((string)$community['name'])?></strong> to delete this community.</p><input name="confirm_name" required><button class="btn" name="action" value="delete_community">Delete Community</button></form></section></div><?php endif;?><?php if($isMember):?><div class="modal" id="inviteModal" hidden><section class="modal-box"><button class="btn" type="button" data-close="inviteModal">Close</button><form class="manage" method="post" id="communityInviteForm"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="community_id" value="<?=$communityId?>"><h2>Invite friends</h2><p class="muted">Choose friends from your contacts. They can accept under Community → Invitations.</p><input class="community-invite-search" type="search" id="communityInviteSearch" placeholder="Search contacts..." autocomplete="off" aria-label="Search contacts"><div class="community-invite-list" id="communityInviteList"><?php if($inviteFriends):?><?php foreach($inviteFriends as$friend):$fname=(string)($friend['display_name']??'Friend');$initials=mb_strtoupper(mb_substr(preg_replace('/\s+/', '', $fname)?:'F',0,2));?><label class="community-invite-option" data-name="<?=h(mb_strtolower($fname))?>"><input type="checkbox" name="invite_user_ids[]" value="<?=(int)$friend['id']?>"><span class="community-invite-avatar"><?=h($initials)?></span><span class="community-invite-name"><?=h($fname)?></span></label><?php endforeach;?><?php else:?><div class="community-invite-empty">No inviteable contacts right now. Friends who are already members or pending invites are hidden.</div><?php endif;?></div><div class="community-invite-actions"><button class="btn" type="button" data-close="inviteModal">Cancel</button><button class="btn primary" name="action" value="invite"<?=$inviteFriends?'':' disabled'?>>Send invites</button></div></form></section></div><?php endif;?><?php if($isMember&&!$isOwner):?><div class="community-action-confirm is-leave" id="leaveModal" hidden><section role="alertdialog" aria-modal="true" aria-labelledby="leaveCommunityTitle"><button class="community-action-x" type="button" data-close="leaveModal" aria-label="Close">&times;</button><span class="community-action-icon"><i class="fa fa-sign-out"></i></span><h2 id="leaveCommunityTitle">Leave <?=h((string)$community['name'])?>?</h2><p>You will lose member access to this community until you join again.</p><div><button class="community-action-cancel" type="button" data-close="leaveModal">Cancel</button><button class="community-action-submit" type="button" id="communityLeaveConfirmBtn">Leave</button></div></section></div><?php endif;?><script>document.querySelectorAll('[data-open]').forEach(b=>b.onclick=()=>document.getElementById(b.dataset.open).hidden=false);document.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>document.getElementById(b.dataset.close).hidden=true);if(location.hash==='#manage'){var m=document.getElementById('manageModal');if(m)m.hidden=false}if(location.hash==='#invite'){var inv=document.getElementById('inviteModal');if(inv)inv.hidden=false}(function(){var search=document.getElementById('communityInviteSearch');var list=document.getElementById('communityInviteList');if(!search||!list)return;search.addEventListener('input',function(){var q=(search.value||'').trim().toLowerCase();list.querySelectorAll('.community-invite-option').forEach(function(row){var name=row.getAttribute('data-name')||'';row.style.display=!q||name.indexOf(q)>=0?'':'none';});});})();(function(){var search=document.getElementById('communityMembersSearch');var list=document.getElementById('communityMembersList');var empty=document.getElementById('communityMembersEmpty');if(!search||!list)return;search.addEventListener('input',function(){var q=(search.value||'').trim().toLowerCase();var shown=0;list.querySelectorAll('.member').forEach(function(row){var name=row.getAttribute('data-name')||'';var match=!q||name.indexOf(q)>=0;row.style.display=match?'':'none';if(match)shown++;});if(empty)empty.hidden=shown>0;});})();(function(){var leaveModal=document.getElementById('leaveModal');var leaveForm=document.getElementById('communityLeaveForm');var leaveBtn=document.getElementById('communityLeaveConfirmBtn');if(leaveModal){leaveModal.addEventListener('click',function(e){if(e.target===leaveModal)leaveModal.hidden=true;});document.addEventListener('keydown',function(e){if(e.key==='Escape'&&leaveModal&&!leaveModal.hidden)leaveModal.hidden=true;});}if(leaveBtn&&leaveForm){leaveBtn.addEventListener('click',function(){leaveBtn.disabled=true;leaveForm.submit();});}})();<?php if($inviteToast): ?> (function(){var old=document.querySelector('.community-post-toast');if(old)old.remove();var note=document.createElement('div');note.className='community-post-toast';note.setAttribute('role','status');note.textContent='The invitation sent.';document.body.appendChild(note);window.setTimeout(function(){note.remove();},2200);try{var u=new URL(window.location.href);u.searchParams.delete('invited');window.history.replaceState({},'',u.pathname+u.search+u.hash);}catch(_e){}})();<?php endif; ?><?php if(!empty($leaveToast)): ?> (function(){var old=document.querySelector('.community-post-toast');if(old)old.remove();var note=document.createElement('div');note.className='community-post-toast';note.setAttribute('role','status');note.textContent='You left this community.';document.body.appendChild(note);window.setTimeout(function(){note.remove();},2200);try{var u=new URL(window.location.href);u.searchParams.delete('left');window.history.replaceState({},'',u.pathname+u.search+u.hash);}catch(_e){}})();<?php endif; ?></script></body></html>
