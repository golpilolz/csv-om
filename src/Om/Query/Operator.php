<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Query;

enum Operator: string
{
    case EQ = '=';
    case NEQ = '<>';
    case GT = '>';
    case GTE = '>=';
    case LT = '<';
    case LTE = '<=';
    case IN = 'IN';
    case NIN = 'NOT IN';
    case CONTAINS = 'CONTAINS';
    case STARTS_WITH = 'STARTS_WITH';
    case ENDS_WITH = 'ENDS_WITH';
}
