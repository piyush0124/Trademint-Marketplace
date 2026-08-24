<?php

namespace App\Enums;

use App\Models\Product;
use App\Models\Service;

/**
 * What a quote line represents. Intentionally four generic shapes that cover
 * every marketplace category — a line is priced the same way whether it is
 * 500 units of steel, a month of digital marketing, a warehouse build or a
 * one-off consultation.
 *
 * No industry-specific type belongs here (no property/apartment/plot). If a
 * category needs richer detail it goes in a category-specific module, not in
 * the core quote architecture.
 */
enum QuoteItemType: string
{
    /** A physical, countable good. May reference a Product from the catalogue. */
    case Product = 'product';
    /** A repeatable service offering. May reference a Service from the catalogue. */
    case Service = 'service';
    /** A scoped piece of work delivered once, priced as a whole. */
    case Project = 'project';
    /** Anything else the business needs to itemise (fees, materials, add-ons). */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Service => 'Service',
            self::Project => 'Project',
            self::Custom => 'Custom',
        };
    }

    /** The catalogue model a line of this type may point at, if any. */
    public function referenceModel(): ?string
    {
        return match ($this) {
            self::Product => Product::class,
            self::Service => Service::class,
            self::Project, self::Custom => null,
        };
    }
}
