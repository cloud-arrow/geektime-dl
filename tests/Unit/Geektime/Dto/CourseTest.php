<?php

declare(strict_types=1);

use App\Geektime\Dto\Article;
use App\Geektime\Dto\Course;

test('fromArray maps all fields correctly', function () {
    $course = Course::fromArray([
        'id' => 42,
        'title' => 'Test Course',
        'type' => 'column',
        'is_video' => true,
        'access' => true,
    ]);
    expect($course->id)->toBe(42);
    expect($course->title)->toBe('Test Course');
    expect($course->type)->toBe('column');
    expect($course->isVideo)->toBeTrue();
    expect($course->access)->toBeTrue();
});

test('fromArray uses defaults for missing fields', function () {
    $course = Course::fromArray([]);
    expect($course->id)->toBe(0);
    expect($course->title)->toBe('');
    expect($course->type)->toBe('');
    expect($course->isVideo)->toBeFalse();
    expect($course->access)->toBeFalse();
    expect($course->articles)->toBe([]);
});

test('isTextCourse returns true for non-video course', function () {
    $course = Course::fromArray(['id' => 1, 'title' => 't', 'is_video' => false]);
    expect($course->isTextCourse())->toBeTrue();
});

test('isTextCourse returns false for video course', function () {
    $course = Course::fromArray(['id' => 1, 'title' => 't', 'is_video' => true]);
    expect($course->isTextCourse())->toBeFalse();
});

test('withArticles returns new instance with different articles', function () {
    $course = Course::fromArray(['id' => 1, 'title' => 'Test']);
    $article = Article::fromArray(['aid' => 10, 'title' => 'Art1']);
    $newCourse = $course->withArticles([$article]);

    expect($course->articles)->toBe([]); // original unchanged
    expect($newCourse->articles)->toHaveCount(1);
    expect($newCourse->articles[0]->aid)->toBe(10);
    expect($newCourse->id)->toBe($course->id); // same id
});

test('fromArray maps nested articles', function () {
    $course = Course::fromArray([
        'id' => 1,
        'title' => 'Test',
        'articles' => [
            ['aid' => 100, 'title' => 'A1'],
            ['aid' => 200, 'title' => 'A2'],
        ],
    ]);
    expect($course->articles)->toHaveCount(2);
    expect($course->articles[0]->aid)->toBe(100);
    expect($course->articles[1]->aid)->toBe(200);
});
