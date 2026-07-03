<?php

session_start();
error_reporting(0);

include('./controller.php');

// ✅ Get PDO connection from controller.php
$controller = new Controller();
$dbh = $controller->__construct();

if(strlen($_SESSION['alogin'])==0){
    header('location:index.php');
    exit;
}else{

    $filename = "Users list";

    // ✅ Headers MUST be before any output
    header("Content-type: application/octet-stream");
    header("Content-Disposition: attachment; filename=".$filename."-report.xls");
    header("Pragma: no-cache");
    header("Expires: 0");
?>
<table border="1">
    <thead>
        <tr>
            <th>#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Gender</th>
            <th>Phone</th>
            <th>Designation</th>
        </tr>
    </thead>

    <tbody>
    <?php
        $sql = "SELECT * FROM Users";
        $query = $dbh->prepare($sql);
        $query->execute();
        $results = $query->fetchAll(PDO::FETCH_OBJ);
        $cnt = 1;

        if($query->rowCount() > 0){
            foreach($results as $result){

                // ✅ Keep your variables style
                $Name = $result->name ?? '';
                $Email = $result->email ?? '';
                $Gender = $result->gender ?? '';
                $Phone = $result->mobile ?? '';
                $Designation = $result->designation ?? '';

                echo '
                <tr>
                    <td>'.$cnt.'</td>
                    <td>'.$Name.'</td>
                    <td>'.$Email.'</td>
                    <td>'.$Gender.'</td>
                    <td>'.$Phone.'</td>
                    <td>'.$Designation.'</td>
                </tr>
                ';

                $cnt++;
            }
        }
    ?>
    </tbody>
</table>
<?php } ?>
