<?php
// Create a file called session_check.php and access it to see your server settings
echo "Session Configuration:<br>";
echo "session.gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . " seconds<br>";
echo "session.cookie_lifetime: " . ini_get('session.cookie_lifetime') . " seconds<br>";
echo "session.gc_probability: " . ini_get('session.gc_probability') . "<br>";
echo "session.gc_divisor: " . ini_get('session.gc_divisor') . "<br>";
echo "session.save_path: " . ini_get('session.save_path') . "<br>";

// Check if session.save_path is writable
if (is_writable(ini_get('session.save_path'))) {
    echo "Session save path is writable<br>";
} else {
    echo "WARNING: Session save path is not writable<br>";
}
?>