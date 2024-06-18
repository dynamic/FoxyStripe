<?php

namespace Dynamic\FoxyStripe\Security;

use SilverStripe\Security\PasswordEncryptor;
use SilverStripe\Security\PasswordEncryptor_EncryptionFailed;

/**
 * Class PasswordEncryptor_BCrypt
 * @package Dynamic\FoxyStripe\Security
 */
class PasswordEncryptor_BCrypt extends PasswordEncryptor
{
    /**
     * Cost of encryption.
     * Higher costs will increase security, but also increase server load.
     * If you are using basic auth, you may need to decrease this as encryption
     * will be run on every request.
     * The two digit cost parameter is the base-2 logarithm of the iteration
     * count for the underlying Blowfish-based hashing algorithmeter and must
     * be in range 04-31, values outside this range will cause crypt() to fail.
     */
    protected static $cost = 10;

    /**
     * Sets the cost of the blowfish algorithm.
     * See {@link PasswordEncryptor_Blowfish::$cost}
     * Cost is set as an integer but
     * Ensure that set values are from 4-31
     *
     * @param int $cost range 4-31
     */
    public static function set_cost($cost)
    {
        self::$cost = max(min(31, $cost), 4);
    }

    /**
     * Gets the cost that is set for the PASSWORD_BCRYPT algorithm
     *
     * @return int
     */
    public static function get_cost()
    {
        return self::$cost;
    }

    /**
     * @param String $password
     * @param null $salt
     * @param null $member
     * @return bool|String
     * @throws PasswordEncryptor_EncryptionFailed
     */
    public function encrypt($password, $salt = null, $member = null)
    {
        $encryptedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => static::get_cost()]);

        if (strpos($encryptedPassword, '$2y$') === false) {
            throw new PasswordEncryptor_EncryptionFailed('BCrypt password encryption failed.');
        }

        return $encryptedPassword;
    }

    /**
     * @param string $hash
     * @param string $password
     * @param null $salt
     * @param null $member
     * @return bool
     */
    public function check($hash, $password, $salt = null, $member = null)
    {
        return password_verify($password, $hash);
    }
}
