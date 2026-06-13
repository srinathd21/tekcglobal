<?php
session_start();
require_once "../includes/db.php";
if ($_SERVER["REQUEST_METHOD"] !== "POST") { header("Location: ../roles.php"); exit; }
function slugify($text){ $text=strtolower(trim($text)); $text=preg_replace('/[^a-z0-9]+/','-',$text); return trim($text,'-'); }
$role_id=(int)($_POST["role_id"]??0); $role_name=trim($_POST["role_name"]??""); $role_slug=trim($_POST["role_slug"]??""); $description=trim($_POST["description"]??""); $is_designation=(int)($_POST["is_designation"]??1); $is_active=(int)($_POST["is_active"]??1); $user_id=$_SESSION["user_id"]??null;
if($role_name===''){ die("Role name is required."); }
$role_slug=$role_slug===''?slugify($role_name):slugify($role_slug);
if($role_id>0){ $stmt=mysqli_prepare($conn,"UPDATE roles SET role_name=?, role_slug=?, description=?, is_designation=?, is_active=?, updated_by=? WHERE id=?"); mysqli_stmt_bind_param($stmt,"sssiiii",$role_name,$role_slug,$description,$is_designation,$is_active,$user_id,$role_id); $activity="UPDATE"; $desc="Updated role details"; }
else { $stmt=mysqli_prepare($conn,"INSERT INTO roles (role_name,role_slug,description,is_designation,is_system,is_active,created_by) VALUES (?,?,?, ?,0,?,?)"); mysqli_stmt_bind_param($stmt,"sssiii",$role_name,$role_slug,$description,$is_designation,$is_active,$user_id); $activity="CREATE"; $desc="Created role"; }
mysqli_stmt_execute($stmt); if($role_id<=0){ $role_id=mysqli_insert_id($conn); } mysqli_stmt_close($stmt);
$employee_id=$_SESSION["employee_id"]??null; $employee_name=$_SESSION["employee_name"]??"Admin"; $username=$_SESSION["username"]??null; $designation=$_SESSION["designation"]??null; $department=$_SESSION["department"]??null; $ip=$_SERVER["REMOTE_ADDR"]??null;
$log=mysqli_prepare($conn,"INSERT INTO activity_logs (employee_id,employee_name,username,designation,department,activity_type,module,description,reference_id,ip_address) VALUES (?,?,?,?,?,?, 'roles',?,?,?)"); mysqli_stmt_bind_param($log,"issssssis",$employee_id,$employee_name,$username,$designation,$department,$activity,$desc,$role_id,$ip); mysqli_stmt_execute($log); mysqli_stmt_close($log);
header("Location: ../roles.php?success=1"); exit;
?>