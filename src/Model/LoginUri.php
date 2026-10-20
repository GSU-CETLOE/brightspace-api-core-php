<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Model;

enum LoginUri : string
{
    case PRE_MFA       = '/d2l/lp/preferences/authenticatorLoginAction/entercoderedirect';
    case LOGIN         = '/d2l/lp/auth/login/login.d2l';
    case MFA           = '/d2l/lp/auth/twofactorauthentication/TwoFactorCodeEntry.d2l';
    case PROCESS_LOGIN = '/d2l/lp/auth/login/ProcessLoginActions.d2l';
    case HOME          = '/d2l/home';
}
