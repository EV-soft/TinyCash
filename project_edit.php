<?php # /project_edit.php v:1.4.0 d:2026-08-23 i:claude
# v1.4.0: viser nu en fejlbesked hvis en dublet-projektkode blev afvist ved
# gem (se [[project-bugs-review]] og project_actions.php).
# htm_InputGroup->htm_Field
# (Færdiggjort: gem virker, settings-kort opdateret)
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php'; 
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';
$module_active = !empty($s['module_projects']) && $s['module_projects'] == '1';

$id = (int)($_GET['id'] ?? 0);
$err = $_GET['err'] ?? '';

if ($id > 0) {
    $res  = DB::query($conn, "SELECT * FROM projects WHERE proj_id = $id");
    $item = DB::fetch_assoc($res);
    if (!$item) die(lang('@Project not found'));
    $pageTitle = lang('@Edit Project');
} else {
    $item = [
        'proj_id'          => 0,
        'proj_no'          => '',
        'cust_id'          => '',
        'proj_start'       => '',
        'proj_stop'        => '',
        'proj_description' => '',
        'proj_concept'     => '',
        'is_active'        => 1,
    ];
    $pageTitle = lang('@New Project');
}

htm_Header($pageTitle);
showMenu();

if ($err === 'missing_code') htm_Alert(lang('@Project Code is required'), 'error');
if ($err === 'duplicate_code') htm_Alert(lang('@This project code is already in use by another project.'), 'error');

$cust_opts = ['' => '-- ' . lang('@Select Customer') . ' --'];
$cust_res  = DB::query($conn, "SELECT cust_id, cust_name FROM customers ORDER BY cust_name ASC");
if ($cust_res) {
    while ($c = DB::fetch_assoc($cust_res)) {
        $cust_opts[$c['cust_id']] = $c['cust_name'];
    }
}

// --- SETTINGS-KORT: modul til/fra ---
$toggle_tool = htm_Field(
    '', '@Module', 'module_projects', $module_active ? '1' : '0',
    'sele', ['1' => lang('@Active'), '0' => lang('@Inactive')],
    extr: 'bare onchange="this.form.submit()"', echo: false
);

echo '<form method="post" action="project_actions.php?action=toggle_module" style="margin:0;">';
csrf_field();
echo '<input type="hidden" name="return_to" value="project_edit.php?id=' . $id . '">';
htm_Card_(lang('@Project Module'), 700, tool: $toggle_tool);
echo '<p style="margin:0; color: var(--text-muted); font-size:0.9em;">';
echo lang('@Activate/deactivate the Project module. Controls visibility of the ProjectCode field in entry forms.') . '<br><br>';
echo lang('@View table of created projects.') . '<br><br>';
echo lang('@ProjectCode enables reporting of a customer\'s financial transactions — income/expenses registered per customer (Customer Account Card).');
echo '</p>';
htm_Card_end();
echo '</form>';

// --- REDIGER/OPRET PROJEKT ---
echo "<div style='max-width:800px; margin:20px auto;'>";
htm_Card_($pageTitle, 700);
?>
<form id="proj_form" action="project_actions.php?action=<?php echo ($id > 0 ? 'update_project' : 'create_project'); ?>" method="POST">
    <?php csrf_field(); ?>
    <input type="hidden" name="proj_id" value="<?php echo $item['proj_id']; ?>">

    <?php
        htm_Field(
            icon: 'fa-hashtag',
            labl: '@Project Code',
            name: 'proj_no',
            valu: $item['proj_no'] ?? '',
            type: 'text',
            extr: 'required autofocus',
            wdth: '28%'
        );
        htm_Field(
            icon: 'fa-user',
            labl: '@Client',
            name: 'cust_id',
            valu: $item['cust_id'] ?? '',
            type: 'sele',
            opti: $cust_opts,
            wdth: '50%'
        );
        htm_Field(
            icon: 'fa-toggle-on',
            labl: '@Status',
            name: 'is_active',
            valu: $item['is_active'] ?? 1,
            type: 'sele',
            opti: ['1' => lang('@Active'), '0' => lang('@Inactive')],
            wdth: '22%'
        );
        htm_Field(
            icon: 'fa-calendar',
            labl: '@Start Date',
            name: 'proj_start',
            valu: $item['proj_start'] ?? '',
            type: 'date',
            wdth: '50%'
        );
        htm_Field(
            icon: 'fa-calendar-check',
            labl: '@End Date',
            name: 'proj_stop',
            valu: $item['proj_stop'] ?? '',
            type: 'date',
            wdth: '50%'
        );
        htm_Field(
            icon: 'fa-align-left',
            labl: '@Project Description',
            name: 'proj_description',
            valu: $item['proj_description'] ?? '',
            type: 'textarea',
            extr: 'rows="3"'
        );
        htm_Field(
            icon: 'fa-file-invoice',
            labl: '@Invoice Concept',
            name: 'proj_concept',
            valu: $item['proj_concept'] ?? '',
            type: 'textarea',
            extr: 'rows="2"',
            hint: '@Maintain a suggested invoicing text for this project'
        );
    ?>

    <div style="margin-top:20px; display:flex; gap:10px;">
        <?php
            htm_Button(
                icon: 'fa-save',
                labl: ($id > 0 ? '@Save Changes' : '@Create Project'),
                type: 'success',
                styl: 'flex:2; padding:12px; font-size:1.1em;',
                attr: 'form="proj_form" data-hint="'.lang($id > 0 ? '@Save changes to this project' : '@Create this project').'"'
            );
        ?>
        <a href="project_view.php<?php echo $id > 0 ? '?id='.$id : ''; ?>" style="text-decoration:none; flex:1;">
            <?php htm_Button(icon: 'fa-times', labl: '@Cancel', type: 'secondary', styl: 'width:100%; padding:12px;', attr: 'data-hint="'.lang('@Discard changes and return to the project').'"'); ?>
        </a>
        <?php if ($id > 0): ?>
        <a href="project_actions.php?action=delete_project&id=<?php echo $id; ?>"
           onclick="return confirm('<?php echo addslashes(lang('@Are you sure? This cannot be undone.')); ?>')"
           style="text-decoration:none; flex:1;">
            <?php htm_Button(icon: 'fa-trash', labl: '@Delete', type: 'danger', styl: 'width:100%; padding:12px;', attr: 'data-hint="'.lang('@Delete this project').'"'); ?>
        </a>
        <?php endif; ?>
    </div>
</form>
<?php
htm_Card_end();
echo "</div>";
htm_Footer();
?>
