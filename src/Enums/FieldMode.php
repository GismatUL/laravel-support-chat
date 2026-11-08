<?php

namespace Gismatul\SupportChat\Enums;

/**
 * How an optional contact input (email, phone) appears in the widget's intro form.
 */
enum FieldMode: string
{
    case Hidden = 'hidden';

    case Optional = 'optional';

    case Required = 'required';
}
