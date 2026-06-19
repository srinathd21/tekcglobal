<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vendor-helper.php';

if(empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])){
    header('Location: ../login.php');
    exit;
}

if(($_GET['action'] ?? '') === 'list_vendor_remarks'){
    header('Content-Type: application/json');

    $pmsItemId = (int)($_GET['pms_item_id'] ?? 0);
    $remarks = [];

    if($pmsItemId > 0){
        $q = mysqli_query($conn, "
            SELECT
                vr.id,
                vr.urgency,
                vr.remark,
                vr.created_at,
                COALESCE(e.full_name, u.username, 'Employee') AS employee_name
            FROM project_vendor_finalization_remarks vr
            LEFT JOIN users u ON u.id=vr.created_by
            LEFT JOIN employees e ON e.id=u.employee_id
            WHERE vr.pms_item_id=$pmsItemId
            ORDER BY vr.created_at DESC, vr.id DESC
        ");

        while($q && ($r=mysqli_fetch_assoc($q))){
            $r['created_at_label'] = !empty($r['created_at']) ? date('d M Y h:i A', strtotime($r['created_at'])) : '-';
            $remarks[] = $r;
        }
    }

    echo json_encode(['success'=>true,'remarks'=>$remarks]);
    exit;
}


function pv($k,$d=''){ return trim((string)($_POST[$k] ?? $d)); }

$action = $_POST['action'] ?? '';
$uid = vf_uid();

try {
    if($action === 'save_vendor'){
        $id = (int)pv('vendor_id');

        if($id > 0) vf_require($conn,'can_edit','vendors.php');
        else vf_require($conn,'can_create','vendors.php');

        $name = pv('vendor_name');
        if($name === '') throw new Exception('Vendor name is required.');

        $status = pv('vendor_status','active');
        if(!in_array($status,['active','inactive','blacklisted'],true)) $status='active';

        if($id > 0){
            $oldQ = mysqli_query($conn,"SELECT vendor_status,vendor_code FROM vendors WHERE id=$id LIMIT 1");
            $old = $oldQ ? mysqli_fetch_assoc($oldQ) : null;
            if(!$old) throw new Exception('Vendor not found.');

            mysqli_query($conn,"
                UPDATE vendors SET
                    vendor_name=".vf_sqlv($conn,$name).",
                    vendor_category=".vf_sqlv($conn,pv('vendor_category')).",
                    contact_person=".vf_sqlv($conn,pv('contact_person')).",
                    mobile_number=".vf_sqlv($conn,pv('mobile_number')).",
                    alternate_mobile=".vf_sqlv($conn,pv('alternate_mobile')).",
                    email=".vf_sqlv($conn,pv('email')).",
                    gst_number=".vf_sqlv($conn,pv('gst_number')).",
                    pan_number=".vf_sqlv($conn,pv('pan_number')).",
                    address=".vf_sqlv($conn,pv('address')).",
                    city=".vf_sqlv($conn,pv('city')).",
                    state=".vf_sqlv($conn,pv('state')).",
                    pincode=".vf_sqlv($conn,pv('pincode')).",
                    bank_name=".vf_sqlv($conn,pv('bank_name')).",
                    bank_account_number=".vf_sqlv($conn,pv('bank_account_number')).",
                    ifsc_code=".vf_sqlv($conn,pv('ifsc_code')).",
                    rating=".vf_sqln(pv('rating')).",
                    vendor_status=".vf_sqlv($conn,$status).",
                    notes=".vf_sqlv($conn,pv('notes')).",
                    updated_by=".($uid?:'NULL')."
                WHERE id=$id
            ");
            if(mysqli_error($conn)) throw new Exception(mysqli_error($conn));

            vf_log($conn,'UPDATE','vendors','Updated vendor '.$old['vendor_code'],$id);
            header('Location: ../vendors.php?updated=1');
            exit;
        }

        $ym = date('Ym');
        $q = mysqli_query($conn,"SELECT vendor_code FROM vendors WHERE vendor_code LIKE 'VEN-$ym-%' ORDER BY id DESC LIMIT 1");
        $next = 1;
        if($q && ($r=mysqli_fetch_assoc($q))){
            $parts = explode('-', $r['vendor_code']);
            $next = ((int)end($parts)) + 1;
        }
        $code = 'VEN-'.$ym.'-'.str_pad((string)$next,4,'0',STR_PAD_LEFT);

        mysqli_query($conn,"
            INSERT INTO vendors
            (vendor_code,vendor_name,vendor_category,contact_person,mobile_number,alternate_mobile,email,gst_number,pan_number,address,city,state,pincode,bank_name,bank_account_number,ifsc_code,rating,vendor_status,notes,created_by,updated_by)
            VALUES(
                ".vf_sqlv($conn,$code).",
                ".vf_sqlv($conn,$name).",
                ".vf_sqlv($conn,pv('vendor_category')).",
                ".vf_sqlv($conn,pv('contact_person')).",
                ".vf_sqlv($conn,pv('mobile_number')).",
                ".vf_sqlv($conn,pv('alternate_mobile')).",
                ".vf_sqlv($conn,pv('email')).",
                ".vf_sqlv($conn,pv('gst_number')).",
                ".vf_sqlv($conn,pv('pan_number')).",
                ".vf_sqlv($conn,pv('address')).",
                ".vf_sqlv($conn,pv('city')).",
                ".vf_sqlv($conn,pv('state')).",
                ".vf_sqlv($conn,pv('pincode')).",
                ".vf_sqlv($conn,pv('bank_name')).",
                ".vf_sqlv($conn,pv('bank_account_number')).",
                ".vf_sqlv($conn,pv('ifsc_code')).",
                ".vf_sqln(pv('rating')).",
                ".vf_sqlv($conn,$status).",
                ".vf_sqlv($conn,pv('notes')).",
                ".($uid?:'NULL').",
                ".($uid?:'NULL')."
            )
        ");
        if(mysqli_error($conn)) throw new Exception(mysqli_error($conn));
        $id = mysqli_insert_id($conn);

        vf_log($conn,'CREATE','vendors','Created vendor '.$code,$id);
        header('Location: ../vendors.php?saved=1');
        exit;
    }

    if($action === 'delete_vendor'){
        vf_require($conn,'can_delete','vendors.php');
        $id = (int)pv('vendor_id');
        if($id<=0) throw new Exception('Invalid vendor.');

        mysqli_query($conn,"UPDATE vendors SET deleted_at=NOW(), deleted_by=".($uid?:'NULL').", vendor_status='inactive' WHERE id=$id");
        if(mysqli_error($conn)) throw new Exception(mysqli_error($conn));

        vf_log($conn,'DELETE','vendors','Deleted vendor',$id);
        header('Location: ../vendors.php?deleted=1');
        exit;
    }

    if($action === 'save_finalization'){
        vf_require($conn,'can_edit','pms-vendor-schedule.php');

        $itemId = (int)pv('pms_item_id');
        $projectId = (int)pv('project_id');
        $scheduleId = (int)pv('schedule_id');
        if($itemId<=0 || $projectId<=0 || $scheduleId<=0) throw new Exception('Invalid PMS vendor item.');

        $itemQ = mysqli_query($conn,"
            SELECT id,title,planned_start_date,planned_end_date
            FROM project_pmc_schedule_items
            WHERE id=$itemId AND project_id=$projectId AND schedule_id=$scheduleId AND item_type='task' AND is_active=1
            LIMIT 1
        ");
        $item = $itemQ ? mysqli_fetch_assoc($itemQ) : null;
        if(!$item) throw new Exception('PMS vendor task not found.');

        $status = pv('finalization_status','pending');
        if(!in_array($status,['pending','shortlisted','negotiation','finalized','rejected','cancelled'],true)) $status='pending';

        $vendorId = (int)pv('vendor_id');
        $selectedDate = pv('selected_date');
        $finalizedBy = 'NULL';
        $finalizedAt = 'NULL';
        if($status === 'finalized'){
            if($vendorId<=0) throw new Exception('Select vendor before marking finalized.');
            if($selectedDate==='') $selectedDate = date('Y-m-d');
            $finalizedBy = $uid ?: 'NULL';
            $finalizedAt = 'NOW()';
        }

        mysqli_query($conn,"
            INSERT INTO project_vendor_finalizations
            (project_id,schedule_id,pms_item_id,vendor_id,package_title,required_start_date,required_finalization_date,selected_date,quotation_amount,final_amount,comparison_notes,finalization_status,remarks,finalized_by,finalized_at,created_by,updated_by)
            VALUES(
                $projectId,
                $scheduleId,
                $itemId,
                ".($vendorId>0?$vendorId:'NULL').",
                ".vf_sqlv($conn,$item['title']).",
                ".vf_sqld($conn,$item['planned_start_date']).",
                ".vf_sqld($conn,$item['planned_end_date']).",
                ".vf_sqld($conn,$selectedDate).",
                ".vf_sqln(pv('quotation_amount')).",
                ".vf_sqln(pv('final_amount')).",
                ".vf_sqlv($conn,pv('comparison_notes')).",
                ".vf_sqlv($conn,$status).",
                ".vf_sqlv($conn,pv('remarks')).",
                $finalizedBy,
                $finalizedAt,
                ".($uid?:'NULL').",
                ".($uid?:'NULL')."
            )
            ON DUPLICATE KEY UPDATE
                vendor_id=VALUES(vendor_id),
                package_title=VALUES(package_title),
                required_start_date=VALUES(required_start_date),
                required_finalization_date=VALUES(required_finalization_date),
                selected_date=VALUES(selected_date),
                quotation_amount=VALUES(quotation_amount),
                final_amount=VALUES(final_amount),
                comparison_notes=VALUES(comparison_notes),
                finalization_status=VALUES(finalization_status),
                remarks=VALUES(remarks),
                finalized_by=IF(VALUES(finalization_status)='finalized', VALUES(finalized_by), finalized_by),
                finalized_at=IF(VALUES(finalization_status)='finalized', NOW(), finalized_at),
                updated_by=VALUES(updated_by)
        ");
        if(mysqli_error($conn)) throw new Exception(mysqli_error($conn));

        $fidQ = mysqli_query($conn,"SELECT id FROM project_vendor_finalizations WHERE pms_item_id=$itemId LIMIT 1");
        $fid = ($fidQ && ($fr=mysqli_fetch_assoc($fidQ))) ? (int)$fr['id'] : $itemId;

        if($status === 'finalized'){
            mysqli_query($conn,"UPDATE project_pmc_schedule_items SET item_status='completed', progress_percent=100, actual_end_date=".vf_sqld($conn,$selectedDate).", updated_by=".($uid?:'NULL')." WHERE id=$itemId");
        } elseif($status === 'negotiation' || $status === 'shortlisted') {
            mysqli_query($conn,"UPDATE project_pmc_schedule_items SET item_status='ongoing', updated_by=".($uid?:'NULL')." WHERE id=$itemId AND item_status <> 'completed'");
        }

        vf_log($conn,'UPDATE','project_vendor_finalizations','Updated vendor finalization for '.$item['title'],$fid);
        header('Location: ../pms-vendor-schedule.php?project_id='.$projectId.'&updated=1#vendor-packages-section');
        exit;
    }


    if($action === 'save_vendor_remark'){
        // Any logged-in employee with this project assigned can add urgent remark.
        $itemId = (int)pv('pms_item_id');
        $projectId = (int)pv('project_id');
        $scheduleId = (int)pv('schedule_id');
        $packageTitle = pv('package_title');
        $remark = pv('remark');
        $urgency = pv('urgency','normal');

        if($itemId<=0 || $projectId<=0 || $scheduleId<=0) throw new Exception('Invalid vendor schedule item.');
        if($remark==='') throw new Exception('Remark is required.');
        if(!in_array($urgency,['normal','urgent','critical'],true)) $urgency='normal';

        $eid = vf_employee_id($conn);
        $hasAssigned = false;
        if(!vf_is_super_admin($conn) && $eid > 0){
            $aq = mysqli_query($conn, "SELECT 1 FROM project_assignments WHERE employee_id=$eid AND project_id=$projectId AND status='active' LIMIT 1");
            $hasAssigned = $aq && mysqli_num_rows($aq) > 0;
        }

        if(!vf_is_super_admin($conn) && !$hasAssigned && !vf_can($conn,'can_edit','pms-vendor-schedule.php')){
            throw new Exception('You can add remark only for assigned project.');
        }

        mysqli_query($conn,"
            INSERT INTO project_vendor_finalization_remarks
            (project_id,schedule_id,pms_item_id,package_title,urgency,remark,created_by)
            VALUES(
                $projectId,
                $scheduleId,
                $itemId,
                ".vf_sqlv($conn,$packageTitle).",
                ".vf_sqlv($conn,$urgency).",
                ".vf_sqlv($conn,$remark).",
                ".($uid?:'NULL')."
            )
        ");
        if(mysqli_error($conn)) throw new Exception(mysqli_error($conn));

        $rid = mysqli_insert_id($conn);
        vf_log($conn,'CREATE','project_vendor_finalization_remarks','Added '.$urgency.' vendor finalization remark for '.$packageTitle,$rid);

        header('Location: ../pms-vendor-schedule.php?project_id='.$projectId.'&updated=1#vendor-packages-section');
        exit;
    }

    if($action === 'save_vendor_update'){
        if(!(vf_can($conn,'can_create','pms-vendor-schedule.php') && vf_can($conn,'can_edit','pms-vendor-schedule.php'))){
            throw new Exception('You do not have add and approve access.');
        }

        $itemId = (int)pv('pms_item_id');
        $projectId = (int)pv('project_id');
        $scheduleId = (int)pv('schedule_id');
        if($itemId<=0 || $projectId<=0 || $scheduleId<=0) throw new Exception('Invalid PMS vendor item.');

        $itemQ = mysqli_query($conn,"
            SELECT id,title,planned_start_date,planned_end_date
            FROM project_pmc_schedule_items
            WHERE id=$itemId AND project_id=$projectId AND schedule_id=$scheduleId AND item_type='task' AND is_active=1
            LIMIT 1
        ");
        $item = $itemQ ? mysqli_fetch_assoc($itemQ) : null;
        if(!$item) throw new Exception('PMS vendor task not found.');

        $status = pv('finalization_status','pending');
        if(!in_array($status,['pending','shortlisted','negotiation','finalized','rejected','cancelled'],true)) $status='pending';

        $vendorId = (int)pv('vendor_id');
        $selectedDate = pv('selected_date');

        $finalizedBy = 'NULL';
        $finalizedAt = 'NULL';
        if($status === 'finalized'){
            if($vendorId<=0) throw new Exception('Select vendor before marking finalized.');
            if($selectedDate==='') $selectedDate = date('Y-m-d');
            $finalizedBy = $uid ?: 'NULL';
            $finalizedAt = 'NOW()';
        }

        mysqli_query($conn,"
            INSERT INTO project_vendor_finalizations
            (project_id,schedule_id,pms_item_id,vendor_id,package_title,required_start_date,required_finalization_date,selected_date,quotation_amount,final_amount,comparison_notes,finalization_status,remarks,finalized_by,finalized_at,created_by,updated_by)
            VALUES(
                $projectId,
                $scheduleId,
                $itemId,
                ".($vendorId>0?$vendorId:'NULL').",
                ".vf_sqlv($conn,$item['title']).",
                ".vf_sqld($conn,$item['planned_start_date']).",
                ".vf_sqld($conn,$item['planned_end_date']).",
                ".vf_sqld($conn,$selectedDate).",
                ".vf_sqln(pv('quotation_amount')).",
                ".vf_sqln(pv('final_amount')).",
                ".vf_sqlv($conn,pv('comparison_notes')).",
                ".vf_sqlv($conn,$status).",
                ".vf_sqlv($conn,pv('remarks')).",
                $finalizedBy,
                $finalizedAt,
                ".($uid?:'NULL').",
                ".($uid?:'NULL')."
            )
            ON DUPLICATE KEY UPDATE
                vendor_id=VALUES(vendor_id),
                package_title=VALUES(package_title),
                required_start_date=VALUES(required_start_date),
                required_finalization_date=VALUES(required_finalization_date),
                selected_date=VALUES(selected_date),
                quotation_amount=VALUES(quotation_amount),
                final_amount=VALUES(final_amount),
                comparison_notes=VALUES(comparison_notes),
                finalization_status=VALUES(finalization_status),
                remarks=VALUES(remarks),
                finalized_by=IF(VALUES(finalization_status)='finalized', VALUES(finalized_by), finalized_by),
                finalized_at=IF(VALUES(finalization_status)='finalized', NOW(), finalized_at),
                updated_by=VALUES(updated_by)
        ");
        if(mysqli_error($conn)) throw new Exception(mysqli_error($conn));

        $fidQ = mysqli_query($conn,"SELECT id FROM project_vendor_finalizations WHERE pms_item_id=$itemId LIMIT 1");
        $fid = ($fidQ && ($fr=mysqli_fetch_assoc($fidQ))) ? (int)$fr['id'] : 0;
        if($fid<=0) throw new Exception('Unable to load finalization record.');

        if($status === 'finalized'){
            mysqli_query($conn,"UPDATE project_pmc_schedule_items SET item_status='completed', progress_percent=100, actual_end_date=".vf_sqld($conn,$selectedDate).", updated_by=".($uid?:'NULL')." WHERE id=$itemId");
        } elseif($status === 'negotiation' || $status === 'shortlisted') {
            mysqli_query($conn,"UPDATE project_pmc_schedule_items SET item_status='ongoing', updated_by=".($uid?:'NULL')." WHERE id=$itemId AND item_status <> 'completed'");
        }

        $titles = $_POST['file_title'] ?? [];
        $types = $_POST['file_type'] ?? [];
        $uploadDirAbs = __DIR__ . '/../uploads/vendor-finalization';
        $uploadDirRel = 'uploads/vendor-finalization';
        if(!is_dir($uploadDirAbs)) @mkdir($uploadDirAbs, 0775, true);

        if(isset($_FILES['vendor_files']) && is_array($_FILES['vendor_files']['name'])){
            foreach($_FILES['vendor_files']['name'] as $i => $originalName){
                if(empty($originalName) || (int)$_FILES['vendor_files']['error'][$i] !== UPLOAD_ERR_OK) continue;

                $title = trim((string)($titles[$i] ?? ''));
                if($title === '') $title = pathinfo($originalName, PATHINFO_FILENAME);

                $type = trim((string)($types[$i] ?? 'other'));
                if(!in_array($type,['quotation','drawing','comparison','approval','other'],true)) $type='other';

                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $safeExt = preg_replace('/[^a-zA-Z0-9]/','',$ext);
                $fileName = 'vf_'.$fid.'_'.time().'_'.$i.'_'.bin2hex(random_bytes(4)).($safeExt?'.'.$safeExt:'');
                $destAbs = $uploadDirAbs . '/' . $fileName;
                $destRel = $uploadDirRel . '/' . $fileName;

                if(move_uploaded_file($_FILES['vendor_files']['tmp_name'][$i], $destAbs)){
                    $size = (int)($_FILES['vendor_files']['size'][$i] ?? 0);
                    mysqli_query($conn,"
                        INSERT INTO project_vendor_finalization_files
                        (finalization_id,project_id,pms_item_id,file_title,file_type,original_name,file_path,file_size,uploaded_by)
                        VALUES(
                            $fid,
                            $projectId,
                            $itemId,
                            ".vf_sqlv($conn,$title).",
                            ".vf_sqlv($conn,$type).",
                            ".vf_sqlv($conn,$originalName).",
                            ".vf_sqlv($conn,$destRel).",
                            $size,
                            ".($uid?:'NULL')."
                        )
                    ");
                }
            }
        }

        vf_log($conn,'UPDATE','project_vendor_finalizations','Updated vendor details/files for '.$item['title'],$fid);
        header('Location: ../vendor-update.php?pms_item_id='.$itemId.'&updated=1');
        exit;
    }


    throw new Exception('Invalid action.');
} catch(Throwable $e) {
    $target = '../vendors.php?error='.urlencode($e->getMessage());
    if($action === 'save_finalization' || $action === 'save_vendor_remark') $target = '../pms-vendor-schedule.php?error='.urlencode($e->getMessage());
    if($action === 'save_vendor_update' && !empty($_POST['pms_item_id'])) $target = '../vendor-update.php?pms_item_id='.(int)$_POST['pms_item_id'].'&error='.urlencode($e->getMessage());
    header('Location: '.$target);
    exit;
}
?>