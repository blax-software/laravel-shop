<?php

namespace Blax\Shop\Enums;

enum ProductAttributeType: string
{
    case TEXT = 'text';
    case SELECT = 'select';
    case COLOR = 'color';
    case IMAGE = 'image';

    public function label(): string
    {
        return match ($this) {
            self::TEXT => 'Text',
            self::SELECT => 'Select',
            self::COLOR => 'Color',
            self::IMAGE => 'Image',
        };
    }
}
