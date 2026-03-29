<?php 
include('db_connect.inc.php');
include('auth.inc.php');
include('menu.inc.php');
include('header.inc.php');
showMenu();

$id = (int)$_GET['id'];
$res = mysqli_query($conn, "SELECT * FROM products WHERE prod_id = $id");
$v = mysqli_fetch_assoc($res);
?>

<div style="max-width:500px; margin:20px auto; font-family:sans-serif;">
    <h2>Opdater vare / Lager</h2>
    <form action="faktura_actions.php?action=opdater_vare" method="POST" style="background:white; padding:20px; border-radius:8px; shadow: 0 2px 5px rgba(0,0,0,0.1);">
        <input type="hidden" name="prod_id" value="<?php echo $v['prod_id']; ?>">
        
        <label>Varenavn:</label><br>
        <input type="text" name="prod_name" value="<?php echo htmlspecialchars($v['prod_name']); ?>" style="width:100%; padding:8px; margin-bottom:15px;">

        <label>Lagerbeholdning (Antal lige nu):</label><br>
        <input type="number" name="prod_stock" value="<?php echo $v['prod_stock']; ?>" style="width:100%; padding:8px; margin-bottom:15px;">

        <label>Salgspris (ekskl. moms):</label><br>
        <input type="text" name="prod_price" value="<?php echo number_format($v['prod_price'], 2, ',', ''); ?>" style="width:100%; padding:8px; margin-bottom:15px;">

        <button type="submit" style="background:#2980b9; color:white; border:none; padding:10px; width:100%; cursor:pointer;">Gem ændringer</button>
    </form>
</div>