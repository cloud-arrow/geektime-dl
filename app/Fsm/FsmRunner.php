<?php

declare(strict_types=1);

namespace App\Fsm;

use App\Config\AppConfig;
use App\Downloader\CourseDownloader;
use App\Enums\State;
use App\Geektime\Client;
use App\Geektime\Dto\Article;
use App\Geektime\Dto\Course;
use App\Geektime\EnterpriseApi;
use App\Geektime\GeektimeApi;
use App\Geektime\UniversityApi;
use App\Ui\ArticleSelect;
use App\Ui\ProductAction;
use App\Ui\ProductIdInput;
use App\Ui\ProductTypeOption;
use App\Ui\ProductTypeSelect;
use Illuminate\Support\Facades\Log;

/**
 * Finite State Machine runner that drives the interactive download workflow.
 *
 * Translated from Go: internal/fsm/runner.go
 *
 * State transitions:
 *   SelectProductType -> InputProductID (user selected a type)
 *   InputProductID -> ProductAction (course loaded, needs article select)
 *   InputProductID -> InputProductID (direct download complete, or error)
 *   InputProductID -> SelectProductType (invalid product code)
 *   ProductAction -> SelectProductType (user chose "go back")
 *   ProductAction -> SelectArticle (user chose "select articles")
 *   ProductAction -> SelectProductType (download all complete)
 *   SelectArticle -> ProductAction (user chose "go back")
 *   SelectArticle -> SelectArticle (download selected article, stay for more)
 */
final class FsmRunner
{
    private State $currentState;

    private ?ProductTypeOption $selectedProductType = null;

    private ?Course $selectedProduct = null;

    private GeektimeApi $geektimeApi;

    private EnterpriseApi $enterpriseApi;

    private UniversityApi $universityApi;

    private ?CourseDownloader $courseDownloader = null;

    private bool $cancelled = false;

    public function __construct(
        private readonly AppConfig $config,
        private readonly Client $client,
    ) {
        $this->currentState = State::SelectProductType;
        $this->geektimeApi = new GeektimeApi($this->client);
        $this->enterpriseApi = new EnterpriseApi($this->client);
        $this->universityApi = new UniversityApi($this->client);
    }

    /**
     * Get or lazily create the CourseDownloader instance.
     *
     * CourseDownloader depends on other agents' work (Task #24), so we create
     * it lazily and allow the FSM to function without it being fully implemented.
     */
    private function getCourseDownloader(): CourseDownloader
    {
        if ($this->courseDownloader === null) {
            $this->courseDownloader = new CourseDownloader(
                config: $this->config,
                geektimeApi: $this->geektimeApi,
                enterpriseApi: $this->enterpriseApi,
                universityApi: $this->universityApi,
            );
        }

        return $this->courseDownloader;
    }

    /**
     * Mark the runner as cancelled (typically from a SIGINT signal).
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * Execute the finite state machine loop.
     *
     * Keeps running until the user exits via Ctrl+C or an unrecoverable error occurs.
     * Each iteration handles the current state, calling the appropriate UI prompt
     * and transitioning to the next state based on user input and API results.
     *
     * @return int Exit code (0 for success)
     */
    public function run(): int
    {
        while (true) {
            if ($this->cancelled) {
                return 0;
            }

            try {
                match ($this->currentState) {
                    State::SelectProductType => $this->handleSelectProductType(),
                    State::InputProductID => $this->handleInputProductID(),
                    State::ProductAction => $this->handleProductAction(),
                    State::SelectArticle => $this->handleSelectArticle(),
                };
            } catch (\Throwable $e) {
                // Check if the user pressed Ctrl+C during a prompt
                if ($this->cancelled) {
                    // Clear line (matching Go behavior)
                    fwrite(STDERR, "\033[1A\033[2K");

                    return 0;
                }

                // GuzzleHttp\Exception\ConnectException with timeout
                if ($e instanceof \GuzzleHttp\Exception\ConnectException
                    && str_contains($e->getMessage(), 'timed out')) {
                    Log::error('Request timed out', ['exception' => $e]);
                    fwrite(STDERR, "请求超时\n");

                    return 1;
                }

                Log::error('An error occurred', ['exception' => $e]);
                fwrite(STDERR, $e->getMessage()."\n");

                return 1;
            }
        }
    }

    /**
     * Handle the SelectProductType state.
     *
     * Shows the product type selection prompt and transitions to InputProductID.
     */
    private function handleSelectProductType(): void
    {
        $this->selectedProductType = ProductTypeSelect::prompt($this->config->isEnterprise);
        $this->currentState = State::InputProductID;
    }

    /**
     * Handle the InputProductID state.
     *
     * Prompts for a product ID, then either loads course info (for products that
     * need article selection) or downloads directly (for daily lessons, case studies).
     */
    private function handleInputProductID(): void
    {
        $productID = ProductIdInput::prompt($this->selectedProductType);

        if ($this->selectedProductType->needSelectArticle) {
            $this->handleInputProductIDIfNeedSelectArticle($productID);
        } else {
            $this->handleInputProductIDIfDownloadDirectly($productID);
        }
    }

    /**
     * Handle the ProductAction state.
     *
     * Shows the action selection prompt (download all, select articles, go back).
     */
    private function handleProductAction(): void
    {
        $action = ProductAction::prompt($this->selectedProduct);

        match ($action) {
            ProductAction::ACTION_GO_BACK => $this->currentState = State::SelectProductType,
            ProductAction::ACTION_DOWNLOAD_ALL => $this->handleDownloadAll(),
            ProductAction::ACTION_SELECT_ARTICLE => $this->currentState = State::SelectArticle,
        };
    }

    /**
     * Handle the SelectArticle state.
     *
     * Shows the article selection list and downloads the selected article,
     * or goes back to ProductAction if the user selects "go back".
     */
    private function handleSelectArticle(): void
    {
        $index = ArticleSelect::prompt($this->selectedProduct->articles);

        $this->handleSelectArticleAction($index);
    }

    /**
     * Load course info for products that support article selection.
     *
     * Routes to the appropriate API based on the product type:
     * - Enterprise mode: uses EnterpriseApi
     * - University/Training camp: uses UniversityApi
     * - Normal courses: uses GeektimeApi with product code validation
     */
    private function handleInputProductIDIfNeedSelectArticle(int $productID): void
    {
        fwrite(STDOUT, "正在加载课程信息...\n");

        if ($this->selectedProductType->isEnterpriseMode) {
            $course = $this->loadEnterpriseCourse($productID);
        } elseif ($this->selectedProductType->isUniversity()) {
            $course = $this->loadUniversityCourse($productID);
        } else {
            $course = $this->loadNormalCourse($productID);
            if ($course === null) {
                // Validation failed or not purchased, state already set
                return;
            }
        }

        if ($course === null) {
            return;
        }

        if (! $course->access) {
            fwrite(STDERR, "尚未购买该课程\n");
            $this->currentState = State::InputProductID;

            return;
        }

        $this->selectedProduct = $course;
        $this->currentState = State::ProductAction;
    }

    /**
     * Handle direct download for products like daily lessons and case studies.
     *
     * These product types don't need article selection; they download a single video directly.
     */
    private function handleInputProductIDIfDownloadDirectly(int $productID): void
    {
        $productData = $this->geektimeApi->productInfo($productID);

        $info = $productData['info'] ?? [];
        $accessMask = (int) ($info['extra']['sub']['access_mask'] ?? 0);

        if ($accessMask === 0) {
            fwrite(STDERR, "尚未购买该课程\n");
            $this->currentState = State::InputProductID;

            return;
        }

        $productType = (string) ($info['type'] ?? '');

        if ($this->validateProductCode($productType)) {
            $title = (string) ($info['title'] ?? '');
            $articleId = (int) ($info['article']['id'] ?? 0);

            $this->getCourseDownloader()->downloadSingleVideoProduct(
                title: $title,
                articleId: $articleId,
                sourceType: $this->selectedProductType->sourceType,
            );
        }

        $this->currentState = State::InputProductID;
    }

    /**
     * Load an enterprise course (info + articles).
     */
    private function loadEnterpriseCourse(int $productID): ?Course
    {
        $courseData = $this->enterpriseApi->productInfo($productID);

        $isMyCourse = (bool) ($courseData['extra']['is_my_course'] ?? false);

        $articlesData = $this->enterpriseApi->articles($productID);
        $articles = [];
        $sections = $articlesData['list'] ?? [];
        foreach ($sections as $section) {
            $sectionTitle = (string) ($section['title'] ?? '');
            $articleList = $section['article_list'] ?? [];
            foreach ($articleList as $articleItem) {
                $a = $articleItem['article'] ?? $articleItem;
                $articles[] = new Article(
                    aid: (int) ($a['id'] ?? $a['article_id'] ?? 0),
                    title: (string) ($a['title'] ?? $a['article_title'] ?? ''),
                    sectionTitle: $sectionTitle,
                );
            }
        }

        return new Course(
            id: $productID,
            title: (string) ($courseData['title'] ?? ''),
            type: '',
            isVideo: true,
            access: $isMyCourse,
            articles: $articles,
        );
    }

    /**
     * Load a university/training camp course (info + articles from lessons).
     */
    private function loadUniversityCourse(int $classID): ?Course
    {
        $classData = $this->universityApi->productInfo($classID);

        // University uses access_mask in a different way:
        // if the response is successful, access is true
        // Error code -5001 means no access (handled at API level)
        $title = (string) ($classData['title'] ?? '');
        $lessons = $classData['lessons'] ?? [];

        $articles = [];
        foreach ($lessons as $lesson) {
            $lessonArticles = $lesson['articles'] ?? [];
            foreach ($lessonArticles as $articleData) {
                $articles[] = new Article(
                    aid: (int) ($articleData['article_id'] ?? $articleData['id'] ?? 0),
                    title: (string) ($articleData['article_title'] ?? $articleData['title'] ?? ''),
                );
            }
        }

        $hasAccess = ! empty($title) || ! empty($articles);

        return new Course(
            id: $classID,
            title: $title,
            type: '',
            isVideo: true,  // Training camp currently only supports video download
            access: $hasAccess,
            articles: $articles,
        );
    }

    /**
     * Load a normal course (column info + articles) with product type validation.
     *
     * Returns null if validation fails (state is set to InputProductID).
     */
    private function loadNormalCourse(int $productID): ?Course
    {
        // Get column info
        $columnData = $this->geektimeApi->columnInfo($productID);

        $course = new Course(
            id: (int) ($columnData['id'] ?? 0),
            title: (string) ($columnData['title'] ?? ''),
            type: (string) ($columnData['type'] ?? ''),
            isVideo: (bool) ($columnData['is_video'] ?? false),
            access: ((int) ($columnData['extra']['sub']['access_mask'] ?? 0)) > 0,
        );

        // Validate product type code
        if (! $this->validateProductCode($course->type)) {
            $this->currentState = State::InputProductID;

            return null;
        }

        if (! $course->access) {
            return $course;
        }

        // Load articles
        $articlesData = $this->geektimeApi->columnArticles($course->id);
        $articles = [];
        foreach ($articlesData as $articleData) {
            $articles[] = new Article(
                aid: (int) ($articleData['id'] ?? 0),
                title: (string) ($articleData['article_title'] ?? $articleData['title'] ?? ''),
            );
        }

        return $course->withArticles($articles);
    }

    /**
     * Validate that the product code matches one of the accepted types for the
     * currently selected product type.
     *
     * Translated from Go: FSMRunner.validateProductCode()
     */
    private function validateProductCode(string $productCode): bool
    {
        if (in_array($productCode, $this->selectedProductType->acceptProductTypes, true)) {
            return true;
        }

        fwrite(STDERR, "\r输入的课程 ID 有误\n");

        return false;
    }

    /**
     * Handle article selection action.
     *
     * Index 0 means "go back", any other index corresponds to articles[index-1].
     */
    private function handleSelectArticleAction(int $index): void
    {
        if ($index === 0) {
            $this->currentState = State::ProductAction;

            return;
        }

        $article = $this->selectedProduct->articles[$index - 1];

        // University/training camp: check if article is video type.
        // Training camp only supports video download; if text, prompt user to re-select.
        if ($this->selectedProductType->isUniversity()) {
            $articleDetail = $this->universityApi->articleInfo(
                $this->selectedProduct->id,
                $article->aid,
            );

            $videoId = (string) ($articleDetail['video_id'] ?? '');
            if ($videoId === '') {
                fwrite(STDOUT, "\r训练营暂时只支持下载视频，请重新选择\n");
                sleep(1);
                $this->currentState = State::SelectArticle;

                return;
            }
        }

        $this->getCourseDownloader()->downloadArticle(
            course: $this->selectedProduct,
            article: $article,
            overwrite: true,
            isUniversity: $this->selectedProductType->isUniversity(),
        );

        fwrite(STDOUT, sprintf("\r%s 下载完成\n", $article->title));
        sleep(1);
        $this->currentState = State::SelectArticle;
    }

    /**
     * Download all articles in the selected course.
     *
     * After completion, returns to the SelectProductType state.
     */
    private function handleDownloadAll(): void
    {
        $this->getCourseDownloader()->downloadAll(
            course: $this->selectedProduct,
            isUniversity: $this->selectedProductType->isUniversity(),
        );

        $this->currentState = State::SelectProductType;
    }
}
