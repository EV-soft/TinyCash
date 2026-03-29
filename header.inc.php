<?php # header.inc.php
# Denne fil inkluderes via htm_Header() funktionen i php2htm.lib.php
# eller direkte i toppen af dine filer.
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - TinyCash</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --bg-color: #f4f7f6;
            --card-bg: #ffffff;
            --text-main: #333;
            --text-muted: #7f8c8d;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-color); 
            color: var(--text-main);
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }

        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 20px; 
        }

        /* Top Bar / Header */
        .top-header {
            background: var(--secondary-color);
            color: white;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .logo { font-weight: 700; font-size: 1.4rem; letter-spacing: -0.5px; }
        .logo span { color: var(--primary-color); }

        /* Card Styling */
        .card {
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 20px;
        }

        .card-title {
            margin-top: 0;
            font-size: 1.2rem;
            color: var(--secondary-color);
            border-bottom: 2px solid var(--bg-color);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        /* Tooltip styling (som du brugte i backup-siden) */
        .tooltip { position: relative; display: inline-block; border-bottom: 1px dotted #ccc; cursor: help; }
        .tooltip .tooltiptext {
            visibility: hidden;
            width: 200px;
            background-color: #555;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .tooltip:hover .tooltiptext { visibility: visible; opacity: 1; }

        @media (max-width: 768px) {
            .container { padding: 10px; }
        }
    </style>
</head>
<body>

<header class="top-header">
    <div class="logo">Tiny<span>Cash</span></div>
    <div class="user-info" style="font-size: 0.9rem;">
        <?php if(isset($_SESSION['user_name'])): ?>
            <?php echo lang('@Welcome'); ?>, <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>
        <?php endif; ?>
    </div>
</header>

<div class="container">
    <h1 style="margin-top: 10px; color: var(--secondary-color); font-size: 1.8rem;">
        <?php echo $page_title; ?>
    </h1>