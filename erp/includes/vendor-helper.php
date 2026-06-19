<?php
if(!function_exists('vf_e')){function vf_e($v){return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');}}
if(!function_exists('vf_table_exists')){function vf_table_exists($conn,$t){$t=preg_replace('/[^a-zA-Z0-9_]/','',(string)$t);$q=mysqli_query($conn,"SHOW TABLES LIKE '$t'");return $q&&mysqli_num_rows($q)>0;}}
if(!function_exists('vf_col_exists')){function vf_col_exists($conn,$t,$c){$t=preg_replace('/[^a-zA-Z0-9_]/','',(string)$t);$c=mysqli_real_escape_string($conn,(string)$c);$q=mysqli_query($conn,"SHOW COLUMNS FROM `$t` LIKE '$c'");return $q&&mysqli_num_rows($q)>0;}}
if(!function_exists('vf_sqlv')){function vf_sqlv($conn,$v){return $v===null?'NULL':"'".mysqli_real_escape_string($conn,(string)$v)."'";}}
if(!function_exists('vf_sqln')){function vf_sqln($v){$v=trim((string)$v);return $v!==''&&is_numeric($v)?(string)$v:'NULL';}}
if(!function_exists('vf_sqld')){function vf_sqld($conn,$v){$v=trim((string)$v);return ($v===''||$v==='0000-00-00')?'NULL':vf_sqlv($conn,$v);}}
if(!function_exists('vf_uid')){function vf_uid(){return (int)($_SESSION['user_id']??0);}}
if(!function_exists('vf_employee_id')){function vf_employee_id($conn){if(!empty($_SESSION['employee_id']))return(int)$_SESSION['employee_id'];$uid=vf_uid();if($uid>0&&vf_table_exists($conn,'users')&&vf_col_exists($conn,'users','employee_id')){$q=mysqli_query($conn,"SELECT employee_id FROM users WHERE id=$uid LIMIT 1");if($q&&($r=mysqli_fetch_assoc($q))&&!empty($r['employee_id'])){$_SESSION['employee_id']=(int)$r['employee_id'];return(int)$r['employee_id'];}}if($uid>0&&vf_table_exists($conn,'employees')&&vf_col_exists($conn,'employees','user_id')){$q=mysqli_query($conn,"SELECT id FROM employees WHERE user_id=$uid LIMIT 1");if($q&&($r=mysqli_fetch_assoc($q))){$_SESSION['employee_id']=(int)$r['id'];return(int)$r['id'];}}return 0;}}
if(!function_exists('vf_employee')){function vf_employee($conn){$eid=vf_employee_id($conn);if($eid<=0)return['id'=>0,'full_name'=>$_SESSION['username']??'System','employee_code'=>$_SESSION['username']??'','role_name'=>'','department_name'=>''];$q=mysqli_query($conn,"SELECT e.*,r.role_name,md.department_name FROM employees e LEFT JOIN roles r ON r.id=e.role_id LEFT JOIN master_departments md ON md.id=e.department_id WHERE e.id=$eid LIMIT 1");return($q&&($r=mysqli_fetch_assoc($q)))?$r:['id'=>$eid,'full_name'=>'System','employee_code'=>'','role_name'=>'','department_name'=>''];}}
if(!function_exists('vf_is_super_admin')){
function vf_is_super_admin($conn){
    if(!empty($_SESSION['role_name']) && strtolower((string)$_SESSION['role_name'])==='super admin') return true;
    if(!empty($_SESSION['role']) && strtolower((string)$_SESSION['role'])==='super admin') return true;

    $uid=vf_uid();
    if($uid<=0 || !vf_table_exists($conn,'user_roles') || !vf_table_exists($conn,'roles')) return false;

    $roleSlugCheck = vf_col_exists($conn,'roles','role_slug') ? " OR LOWER(COALESCE(r.role_slug,''))='super-admin' " : "";
    $q=mysqli_query($conn,"
        SELECT r.id
        FROM user_roles ur
        INNER JOIN roles r ON r.id=ur.role_id
        WHERE ur.user_id=$uid
          AND (
            r.id=1
            OR LOWER(COALESCE(r.role_name,''))='super admin'
            $roleSlugCheck
          )
        LIMIT 1
    ");
    return $q && mysqli_num_rows($q)>0;
}}
if(!function_exists('vf_can')){function vf_can($conn,$perm,$menu){if(vf_is_super_admin($conn))return true;$ok=['can_view','can_create','can_edit','can_delete'];if(!in_array($perm,$ok,true))return false;if(function_exists('can_view')&&$perm==='can_view')return can_view($conn,$menu);if(function_exists('can_create')&&$perm==='can_create')return can_create($conn,$menu);if(function_exists('can_edit')&&$perm==='can_edit')return can_edit($conn,$menu);if(function_exists('can_delete')&&$perm==='can_delete')return can_delete($conn,$menu);if(!vf_table_exists($conn,'role_sidebar_access')||!vf_table_exists($conn,'sidebar_menus')||!vf_table_exists($conn,'user_roles'))return false;$uid=vf_uid();$m=mysqli_real_escape_string($conn,$menu);$q=mysqli_query($conn,"SELECT MAX(COALESCE(rsa.$perm,0)) allowed FROM user_roles ur INNER JOIN role_sidebar_access rsa ON rsa.role_id=ur.role_id INNER JOIN sidebar_menus sm ON sm.id=rsa.menu_id WHERE ur.user_id=$uid AND sm.menu_url='$m' AND sm.is_active=1");return$q&&($r=mysqli_fetch_assoc($q))&&(int)($r['allowed']??0)===1;}}
if(!function_exists('vf_require')){function vf_require($conn,$perm,$menu){if(function_exists('require_permission')){require_permission($conn,$perm,$menu);return;}if(!vf_can($conn,$perm,$menu)){header("Location: dashboard.php?error=".urlencode("Permission denied."));exit;}}}
if(!function_exists('vf_badge')){function vf_badge($s){$s=strtolower((string)$s);if(in_array($s,['active','approved','finalized','completed','selected'],true))return'green';if(in_array($s,['pending','shortlisted','negotiation','ongoing','draft'],true))return'amber';if(in_array($s,['rejected','cancelled','inactive','blacklisted','overdue'],true))return'red';return'blue';}}
if(!function_exists('vf_log')){function vf_log($conn,$type,$module,$desc,$ref=null){if(!vf_table_exists($conn,'activity_logs'))return;$emp=vf_employee($conn);$eid=!empty($emp['id'])?(int)$emp['id']:'NULL';$employeeName=$emp['full_name']??($_SESSION['username']??'System');$username=$_SESSION['username']??($emp['employee_code']??'');$designation=$emp['role_name']??'';$department=$emp['department_name']??'';$ip=$_SERVER['REMOTE_ADDR']??'';mysqli_query($conn,"INSERT INTO activity_logs(employee_id,employee_name,username,designation,department,activity_type,module,description,reference_id,ip_address) VALUES ($eid,".vf_sqlv($conn,$employeeName).",".vf_sqlv($conn,$username).",".vf_sqlv($conn,$designation).",".vf_sqlv($conn,$department).",".vf_sqlv($conn,$type).",".vf_sqlv($conn,$module).",".vf_sqlv($conn,$desc).",".vf_sqln($ref).",".vf_sqlv($conn,$ip).")");}}
?>