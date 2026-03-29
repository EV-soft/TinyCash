<?php # footer.inc.php
// Vi antager, at denne fil inkluderes i bunden af alle .page.php filer.
?>
    </div> <footer style="margin-top: 50px; padding: 20px; border-top: 1px solid #eee; color: #7f8c8d; font-family: sans-serif; font-size: 0.85em; text-align: center;">
        <div style="display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto;">
            
            <div>
                &copy; <?php echo date('Y'); ?> **TinyCash** - <?php echo lang('@All rights reserved'); ?>
            </div>

            <div>
                <?php echo lang('@Logged in as'); ?>: 
                <span style="color: #2c3e50; font-weight: bold;">
                    <?php echo htmlspecialchars($_SESSION['user_name'] ?? lang('@Guest')); ?>
                </span>
            </div>

            <div>
                <?php echo lang('@Version'); ?>: <span style="background: #eee; padding: 2px 6px; border-radius: 3px;">1.2.0-stable</span>
            </div>

        </div>
    </footer>

    <script src="js/main.js"></script>

</body>
</html>