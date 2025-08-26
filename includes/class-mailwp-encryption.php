<?php
/**
 * MailWP Encryption functionality
 * 
 * @package MailWP
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class that handles encryption/decryption of sensitive data
 */
class MailWP_Encryption {
    
    /**
     * Encrypt sensitive data using WordPress security keys
     * 
     * @param string $data Data to encrypt
     * @return string|false Encrypted data (base64 encoded) or false on failure
     */
    public static function encrypt($data) {
        if (empty($data)) {
            return $data;
        }
        
        // Check if encryption is enabled
        if (!get_option('mailwp_enable_encryption', false)) {
            return $data;
        }
        
        // Get encryption key from WordPress constants
        $key = self::get_encryption_key();
        if (!$key) {
            return $data; // Fallback to unencrypted if no key available
        }
        
        try {
            // Generate a random IV
            $iv = openssl_random_pseudo_bytes(16);
            if ($iv === false) {
                return $data;
            }
            
            // Encrypt the data
            $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
            if ($encrypted === false) {
                return $data;
            }
            
            // Combine IV and encrypted data, then base64 encode
            $result = base64_encode($iv . $encrypted);
            
            return $result;
        } catch (Exception $e) {
            // Log error if debugging is enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MailWP Encryption Error: ' . $e->getMessage());
            }
            return $data; // Return original data on encryption failure
        }
    }
    
    /**
     * Decrypt sensitive data using WordPress security keys
     * 
     * @param string $encrypted_data Encrypted data (base64 encoded)
     * @return string|false Decrypted data or false on failure
     */
    public static function decrypt($encrypted_data) {
        if (empty($encrypted_data)) {
            return $encrypted_data;
        }
        
        // Check if encryption is enabled
        if (!get_option('mailwp_enable_encryption', false)) {
            return $encrypted_data;
        }
        
        // Get encryption key from WordPress constants
        $key = self::get_encryption_key();
        if (!$key) {
            return $encrypted_data; // Fallback to treat as unencrypted
        }
        
        try {
            // Decode base64
            $data = base64_decode($encrypted_data, true);
            if ($data === false) {
                return $encrypted_data; // Not base64, treat as unencrypted
            }
            
            // Extract IV (first 16 bytes) and encrypted data
            if (strlen($data) < 16) {
                return $encrypted_data; // Invalid data, treat as unencrypted
            }
            
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            
            // Decrypt the data
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
            if ($decrypted === false) {
                return $encrypted_data; // Decryption failed, treat as unencrypted
            }
            
            return $decrypted;
        } catch (Exception $e) {
            // Log error if debugging is enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MailWP Decryption Error: ' . $e->getMessage());
            }
            return $encrypted_data; // Return original data on decryption failure
        }
    }
    
    /**
     * Generate encryption key from WordPress security constants
     * 
     * @return string|false Encryption key or false if no constants available
     */
    private static function get_encryption_key() {
        // Use WordPress security constants to generate a key
        $constants = [
            'AUTH_KEY',
            'SECURE_AUTH_KEY', 
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT'
        ];
        
        $key_material = '';
        foreach ($constants as $constant) {
            if (defined($constant)) {
                $key_material .= constant($constant);
            }
        }
        
        // If no constants are defined, return false
        if (empty($key_material)) {
            return false;
        }
        
        // Generate a 32-byte key using hash
        return hash('sha256', $key_material . 'mailwp_encryption', true);
    }
    
    /**
     * Check if encryption is possible (WordPress security constants are defined)
     * 
     * @return bool True if encryption is possible, false otherwise
     */
    public static function is_encryption_possible() {
        return self::get_encryption_key() !== false;
    }
    
    /**
     * Get a list of sensitive option names that should be encrypted
     * 
     * @return array List of option names
     */
    public static function get_sensitive_options() {
        return [
            'mailwp_smtp_password',
            'mailwp_msauth_client_secret',
            'mailwp_msauth_access_token',
            'mailwp_msauth_refresh_token'
        ];
    }
    
    /**
     * Encrypt a value before storing in database if it's a sensitive option
     * 
     * @param string $option_name Option name
     * @param mixed $value Value to potentially encrypt
     * @return mixed Encrypted value if sensitive, original value otherwise
     */
    public static function maybe_encrypt_option($option_name, $value) {
        if (in_array($option_name, self::get_sensitive_options())) {
            return self::encrypt($value);
        }
        return $value;
    }
    
    /**
     * Decrypt a value after retrieving from database if it's a sensitive option
     * 
     * @param string $option_name Option name
     * @param mixed $value Value to potentially decrypt
     * @return mixed Decrypted value if sensitive, original value otherwise
     */
    public static function maybe_decrypt_option($option_name, $value) {
        if (in_array($option_name, self::get_sensitive_options())) {
            return self::decrypt($value);
        }
        return $value;
    }
    
    /**
     * Migrate existing unencrypted data to encrypted format
     * 
     * @return bool True on success, false on failure
     */
    public static function migrate_to_encrypted() {
        if (!self::is_encryption_possible()) {
            return false;
        }
        
        $sensitive_options = self::get_sensitive_options();
        $migrated = false;
        
        foreach ($sensitive_options as $option_name) {
            $value = get_option($option_name, '');
            if (!empty($value)) {
                // Check if it's already encrypted by trying to decrypt it
                $decrypted = self::decrypt($value);
                
                // If decrypt returns the same value, it's likely not encrypted
                if ($decrypted === $value) {
                    // Encrypt and update the option
                    $encrypted = self::encrypt($value);
                    if ($encrypted !== $value) {
                        update_option($option_name, $encrypted);
                        $migrated = true;
                    }
                }
            }
        }
        
        return $migrated;
    }
    
    /**
     * Migrate encrypted data back to unencrypted format
     * 
     * @return bool True on success, false on failure
     */
    public static function migrate_to_unencrypted() {
        $sensitive_options = self::get_sensitive_options();
        $migrated = false;
        
        foreach ($sensitive_options as $option_name) {
            $value = get_option($option_name, '');
            if (!empty($value)) {
                // Try to decrypt the value
                $decrypted = self::decrypt($value);
                
                // If decrypt returns a different value, it was encrypted
                if ($decrypted !== $value) {
                    // Update with decrypted value
                    update_option($option_name, $decrypted);
                    $migrated = true;
                }
            }
        }
        
        return $migrated;
    }
}
