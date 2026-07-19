<?php
declare(strict_types=1);

/**
 * Seller customer chat panel for sales_management.php#message.
 *
 * Expected vars:
 * - array $sellerBuyerMsgContacts
 * - array|null $sellerBuyerMsgActive
 * - string $sellerBuyerMsgDraft
 * - int $sellerBuyerMsgAboutProduct
 * - string $sellerBuyerMsgAboutOrder
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$sellerBuyerMsgContacts = is_array($sellerBuyerMsgContacts ?? null) ? $sellerBuyerMsgContacts : [];
$sellerBuyerMsgActive = is_array($sellerBuyerMsgActive ?? null) ? $sellerBuyerMsgActive : null;
$sellerBuyerMsgDraft = (string)($sellerBuyerMsgDraft ?? '');
$sellerBuyerMsgAboutProduct = (int)($sellerBuyerMsgAboutProduct ?? 0);
$sellerBuyerMsgAboutOrder = (string)($sellerBuyerMsgAboutOrder ?? '');
$activePeer = strtoupper(trim((string)($sellerBuyerMsgActive['friend_code'] ?? '')));
$activeName = trim((string)($sellerBuyerMsgActive['buyer_name'] ?? 'Customer'));
?>
<style>
  .seller-buyer-msg-layout{display:grid;grid-template-columns:minmax(200px,260px) minmax(0,1fr);gap:14px;min-height:440px;}
  .seller-buyer-msg-list{border:1px solid rgba(148,163,184,.35);border-radius:8px;overflow:auto;max-height:560px;background:var(--card-bg,transparent);}
  .seller-buyer-msg-item{display:block;padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.25);color:inherit;text-decoration:none;}
  .seller-buyer-msg-item:hover,.seller-buyer-msg-item.is-active{background:rgba(148,163,184,.12);text-decoration:none;}
  .seller-buyer-msg-item strong{display:block;font-size:13px;}
  .seller-buyer-msg-item span{display:block;font-size:11px;opacity:.75;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .seller-buyer-msg-chat{border:1px solid rgba(148,163,184,.35);border-radius:8px;display:flex;flex-direction:column;min-height:440px;max-height:560px;background:var(--card-bg,transparent);}
  .seller-buyer-msg-head{padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.25);font-weight:800;font-size:14px;}
  .seller-buyer-msg-thread{flex:1 1 auto;overflow:auto;padding:12px;display:flex;flex-direction:column;gap:8px;}
  .seller-buyer-msg-bubble{max-width:85%;padding:8px 10px;border-radius:10px;font-size:13px;line-height:1.4;white-space:pre-wrap;word-break:break-word;}
  .seller-buyer-msg-bubble.me{align-self:flex-end;background:#2563eb;color:#fff;}
  .seller-buyer-msg-bubble.them{align-self:flex-start;background:rgba(148,163,184,.18);}
  .seller-buyer-msg-meta{font-size:10px;opacity:.7;margin-top:4px;}
  .seller-buyer-msg-compose{display:flex;gap:8px;padding:10px;border-top:1px solid rgba(148,163,184,.25);align-items:flex-end;}
  .seller-buyer-msg-compose textarea{flex:1 1 auto;min-height:44px;max-height:120px;resize:none;}
  .seller-buyer-msg-compose #sellerBuyerMsgSend,
  body.org-app .commerce-page .seller-buyer-msg-compose #sellerBuyerMsgSend.btn.btn-primary{
    flex:0 0 auto;
    align-self:stretch;
    min-width:72px;
    background-color:var(--org-btn-filled-bg, var(--org-accent, #2563eb)) !important;
    border:1px solid var(--org-btn-filled-bg, var(--org-accent-strong, #1d4ed8)) !important;
    color:var(--org-btn-filled-text, #ffffff) !important;
    -webkit-text-fill-color:var(--org-btn-filled-text, #ffffff) !important;
    font-weight:800;
  }
  .seller-buyer-msg-compose #sellerBuyerMsgSend:hover,
  .seller-buyer-msg-compose #sellerBuyerMsgSend:focus,
  body.org-app .commerce-page .seller-buyer-msg-compose #sellerBuyerMsgSend.btn.btn-primary:hover,
  body.org-app .commerce-page .seller-buyer-msg-compose #sellerBuyerMsgSend.btn.btn-primary:focus{
    background-color:var(--msb-palette-btn-hover-bg, var(--org-accent-strong, #1d4ed8)) !important;
    border-color:var(--msb-palette-btn-hover-bg, var(--org-accent-strong, #1d4ed8)) !important;
    color:#ffffff !important;
    -webkit-text-fill-color:#ffffff !important;
  }
  .seller-buyer-msg-compose #sellerBuyerMsgSend:disabled{
    opacity:.55;
    cursor:not-allowed;
  }
  .seller-buyer-msg-empty{padding:28px 14px;text-align:center;opacity:.85;font-size:13px;}
  .seller-buyer-msg-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#dc3545;color:#fff;font-size:10px;font-weight:800;margin-left:6px;}
  @media (max-width:900px){.seller-buyer-msg-layout{grid-template-columns:1fr;}}
</style>

<div class="sales-management-detail-head">
  <div>
    <p class="sales-management-kicker">Messages</p>
    <h1>Customer chat</h1>
    <p>Receive and reply to customer questions about products, orders, pickup, and delivery.</p>
  </div>
</div>

<?php if (!$sellerBuyerMsgContacts): ?>
  <div class="seller-buyer-msg-empty">
    No customer chats yet. When a buyer messages you from the shop, their thread appears here.
  </div>
<?php else: ?>
  <div class="seller-buyer-msg-layout" id="sellerBuyerMsgRoot"
    data-peer="<?= h($activePeer) ?>"
    data-peer-name="<?= h($activeName) ?>"
    data-draft="<?= h($sellerBuyerMsgDraft) ?>"
  >
    <div class="seller-buyer-msg-list" aria-label="Customers">
      <?php foreach ($sellerBuyerMsgContacts as $c):
        $cid = (int)($c['buyer_user_id'] ?? 0);
        $isActive = $cid === (int)($sellerBuyerMsgActive['buyer_user_id'] ?? 0);
        $href = function_exists('commerce_message_buyer_sales_url')
          ? commerce_message_buyer_sales_url(
              $cid,
              $isActive ? $sellerBuyerMsgAboutProduct : 0,
              $isActive ? $sellerBuyerMsgAboutOrder : ''
          )
          : ('sales_management.php?buyer_msg=' . $cid . '#message');
      ?>
        <a class="seller-buyer-msg-item<?= $isActive ? ' is-active' : '' ?>" href="<?= h($href) ?>" data-peer="<?= h((string)($c['friend_code'] ?? '')) ?>">
          <strong>
            <?= h((string)($c['buyer_name'] ?? 'Customer')) ?>
            <?php if ((int)($c['unread'] ?? 0) > 0): ?>
              <span class="seller-buyer-msg-badge"><?= (int)$c['unread'] ?></span>
            <?php endif; ?>
          </strong>
          <span><?= h((string)(($c['last_message'] !== '' ? $c['last_message'] : (($c['order_code'] ?? '') !== '' ? ('Order ' . $c['order_code']) : 'Start a conversation')))) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="seller-buyer-msg-chat">
      <div class="seller-buyer-msg-head" id="sellerBuyerMsgHead"><?= h($activeName) ?></div>
      <div class="seller-buyer-msg-thread" id="sellerBuyerMsgThread" aria-live="polite"></div>
      <div class="seller-buyer-msg-compose">
        <textarea id="sellerBuyerMsgInput" class="form-control" rows="2" placeholder="Reply about the product or order…"></textarea>
        <button type="button" class="btn btn-primary btn-sm" id="sellerBuyerMsgSend" aria-label="Send message">Send</button>
      </div>
      <p class="tx-danger tx-12 mg-b-0" id="sellerBuyerMsgErr" style="padding:0 10px 8px;" hidden></p>
    </div>
  </div>
  <script>
  (function () {
    var root = document.getElementById('sellerBuyerMsgRoot');
    if (!root) return;
    var thread = document.getElementById('sellerBuyerMsgThread');
    var input = document.getElementById('sellerBuyerMsgInput');
    var sendBtn = document.getElementById('sellerBuyerMsgSend');
    var errEl = document.getElementById('sellerBuyerMsgErr');
    var peer = String(root.getAttribute('data-peer') || '').trim().toUpperCase();
    var draft = String(root.getAttribute('data-draft') || '');
    var lastId = 0;
    var polling = false;
    var endpoint = 'ajax/seller_buyer_chat.php';

    function setErr(msg) {
      if (!errEl) return;
      if (!msg) { errEl.hidden = true; errEl.textContent = ''; return; }
      errEl.hidden = false;
      errEl.textContent = msg;
    }
    function esc(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function appendItems(items, replace) {
      if (!thread) return;
      if (replace) thread.innerHTML = '';
      (items || []).forEach(function (item) {
        var id = parseInt(item.id || 0, 10);
        if (id > lastId) lastId = id;
        var div = document.createElement('div');
        div.className = 'seller-buyer-msg-bubble ' + (item.is_me ? 'me' : 'them');
        div.innerHTML = esc(item.text || '') + '<div class="seller-buyer-msg-meta">' + esc(item.time_label || '') + '</div>';
        thread.appendChild(div);
      });
      thread.scrollTop = thread.scrollHeight;
    }
    async function loadHistory() {
      if (!peer) return;
      try {
        var res = await fetch(endpoint + '?mode=history&peer=' + encodeURIComponent(peer) + '&after=0&mark=1', { credentials: 'same-origin' });
        var data = await res.json();
        if (data && data.ok) {
          lastId = 0;
          appendItems(data.items || [], true);
          if (!(data.items || []).length) {
            thread.innerHTML = '<div class="seller-buyer-msg-empty">No messages yet. Reply when the customer writes, or send the first note about their order.</div>';
          }
        } else if (data && data.error) {
          setErr(data.error);
        }
      } catch (e) { /* ignore */ }
    }
    async function pollNew() {
      if (!peer || polling) return;
      polling = true;
      try {
        var res = await fetch(endpoint + '?mode=poll&peer=' + encodeURIComponent(peer) + '&after=' + lastId + '&mark=1', { credentials: 'same-origin' });
        var data = await res.json();
        if (data && data.ok && (data.items || []).length) {
          if (thread && thread.querySelector('.seller-buyer-msg-empty')) thread.innerHTML = '';
          appendItems(data.items, false);
        }
      } catch (e) { /* ignore */ }
      polling = false;
    }
    async function sendMessage() {
      setErr('');
      if (!peer) { setErr('Select a customer first.'); return; }
      var text = input ? String(input.value || '').trim() : '';
      if (!text) { setErr('Type a message.'); return; }
      if (sendBtn) sendBtn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('mode', 'send');
        body.set('peer', peer);
        body.set('message', text);
        var res = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        if (!data || !data.ok) {
          setErr((data && data.error) || 'Could not send.');
          return;
        }
        if (input) input.value = '';
        if (data.item) {
          if (thread && thread.querySelector('.seller-buyer-msg-empty')) thread.innerHTML = '';
          appendItems([data.item], false);
        } else {
          await pollNew();
        }
      } catch (e) {
        setErr('Could not send message.');
      } finally {
        if (sendBtn) sendBtn.disabled = false;
      }
    }

    if (input && draft && !String(input.value || '').trim()) {
      input.value = draft;
    }
    if (sendBtn) sendBtn.addEventListener('click', sendMessage);
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
        }
      });
    }
    loadHistory();
    setInterval(pollNew, 4000);
  })();
  </script>
<?php endif; ?>
