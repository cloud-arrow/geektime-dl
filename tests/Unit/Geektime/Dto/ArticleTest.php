<?php

declare(strict_types=1);

use App\Geektime\Dto\Article;

test('fromArray maps primary key names', function () {
    $article = Article::fromArray([
        'aid' => 42,
        'title' => 'Test Article',
        'section_title' => 'Section 1',
        'content' => '<p>Hello</p>',
        'audio_download_url' => 'https://example.com/audio.mp3',
        'video_id' => 'vid_123',
        'is_video' => true,
        'in_pvip' => 1,
    ]);
    expect($article->aid)->toBe(42);
    expect($article->title)->toBe('Test Article');
    expect($article->sectionTitle)->toBe('Section 1');
    expect($article->content)->toBe('<p>Hello</p>');
    expect($article->audioDownloadUrl)->toBe('https://example.com/audio.mp3');
    expect($article->videoId)->toBe('vid_123');
    expect($article->isVideo)->toBeTrue();
    expect($article->inPvip)->toBe(1);
});

test('fromArray supports alternate key id', function () {
    $article = Article::fromArray(['id' => 99]);
    expect($article->aid)->toBe(99);
});

test('fromArray supports alternate key article_id', function () {
    $article = Article::fromArray(['article_id' => 77]);
    expect($article->aid)->toBe(77);
});

test('fromArray supports alternate key article_title', function () {
    $article = Article::fromArray(['article_title' => 'Alt Title']);
    expect($article->title)->toBe('Alt Title');
});

test('fromArray supports alternate key chapter_name', function () {
    $article = Article::fromArray(['chapter_name' => 'Chapter 1']);
    expect($article->sectionTitle)->toBe('Chapter 1');
});

test('fromArray supports alternate key article_content', function () {
    $article = Article::fromArray(['article_content' => '<p>Content</p>']);
    expect($article->content)->toBe('<p>Content</p>');
});

test('fromArray supports nested video.id', function () {
    $article = Article::fromArray(['video' => ['id' => 'nested_vid']]);
    expect($article->videoId)->toBe('nested_vid');
});

test('fromArray uses defaults for missing fields', function () {
    $article = Article::fromArray([]);
    expect($article->aid)->toBe(0);
    expect($article->title)->toBe('');
    expect($article->sectionTitle)->toBe('');
    expect($article->content)->toBe('');
    expect($article->audioDownloadUrl)->toBe('');
    expect($article->videoId)->toBe('');
    expect($article->isVideo)->toBeFalse();
    expect($article->inPvip)->toBe(0);
    expect($article->inlineVideoSubtitles)->toBe([]);
});
