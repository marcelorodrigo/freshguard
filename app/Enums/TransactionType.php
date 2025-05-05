<?php

namespace App\Enums;

enum TransactionType: string
{
    case ADD = 'add';
    case REMOVE = 'remove';
}
