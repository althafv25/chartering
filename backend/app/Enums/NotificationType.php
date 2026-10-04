<?php

namespace App\Enums;

enum NotificationType: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
}
