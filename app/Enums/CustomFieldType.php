<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CustomFieldType: string
{
    use HasOptions;

    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texte',
            self::Number => 'Nombre',
            self::Date => 'Date',
            self::Boolean => 'Oui / non',
            self::Select => 'Liste de choix',
            self::Url => 'Adresse web',
        };
    }

    /**
     * Colonne de custom_field_values utilisée pour ce type.
     */
    public function column(): string
    {
        return match ($this) {
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Boolean => 'value_boolean',
            default => 'value_text',
        };
    }
}
