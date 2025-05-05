<?php

namespace Database\Factories\FakeProvider;

class ItemProvider extends \Faker\Provider\Base
{
    private $names = [
        'Flour',
        'Sugar',
        'Pasta',
        'Rice',
        'Beans',
        'Tomato Sauce',
        'Olive Oil',
        'Lasagna Sheets',
        'Canned Tomatoes',
        'Erva de chimarrão',
        'Coffee Beans',
        'Soda',
        'Juice',
        'Milk',
        'Yogurt',
        'Cheese',
        'Butter',
        'Eggs',
        'Bread',
        'Croissant',
        'Bagel',
        'Pita Bread',
        'Tortilla',
        'Cereal',
        'Granola',
        'Oats',
        'Peanut Butter',
        'Jam',
        'Honey',
        'Maple Syrup',
    ];

    public function itemName(): string
    {
        return $this->generator->randomElement($this->names);
    }
}
