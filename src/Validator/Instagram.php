<?php

/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace SionModel\Validator;

use Zend\Validator\Regex;

class Instagram extends Regex
{
    public const INSTAGRAM_USER_REGEX = "/^[A-Za-z0-9_](?:(?:[A-Za-z0-9_]|(?:\.(?!\.))){0,28}(?:[A-Za-z0-9_]))?$/";
    /**
     * Sets validator options
     *
     */
    public function __construct()
    {
        $pattern = self::INSTAGRAM_USER_REGEX;
        $newMessage = 'Instagram user names should begin with a letter, contain only letters, '
            . 'numbers, \'.\', or \'_\' and be between 1 and 30 characters long.';
        $this->messageTemplates[self::INVALID] = $newMessage;
        $this->messageTemplates[self::NOT_MATCH] = $newMessage;
        $this->messageTemplates[self::ERROROUS] = $newMessage;
        parent::__construct($pattern);
    }
}
