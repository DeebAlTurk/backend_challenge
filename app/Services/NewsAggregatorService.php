<?php

namespace App\Services;

use App\Models\Article;
use Exception;
use Guardian\GuardianAPI;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use jcobhams\NewsApi\NewsApi;

class NewsAggregatorService
{
    /**
     * Fetch articles from NewsAPI.org and store them in the database.
     *
     * @return bool
     */
    public function fetchNewsApi(): bool
    {
        try {
            $newsapi = new NewsApi(env('NEWS_API_KEY'));
            $query = null;
            $sources = null;
            $country = 'us';
            $category = null;
            $pageSize = 20;
            $page = 1;

            $response = $newsapi->getTopHeadlines($query, $sources, $country, $category, $pageSize, $page);

            if (isset($response->articles) && is_array($response->articles)) {
                foreach ($response->articles as $article) {
                    Article::updateOrCreate(
                        ['url' => $article->url],
                        [
                            'title' => $article->title,
                            'author' => $article->author ?? 'Unknown',
                            'description' => $article->description,
                            'source' => "News API",
                            'category' => $category ?? 'General',
                            'tags' => [$query], // Store as an array
                            'published_at' => $this->formatPublishedDate($article->publishedAt),
                        ]
                    );
                }
            }

            return true;
        } catch (Exception $e) {
            Log::error("NewsAPI Fetch Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search for articles in NewsAPI.org.
     *
     * @param array $parameters
     * @return Collection|bool
     */
    public function SearchNewsApi(array $parameters): Collection|bool
    {
        try {
            $newsapi = new NewsApi(env('NEWS_API_KEY'));
            $query = $parameters['search'] ?? null;
            $category = $parameters['category'] ?? null;
            $pageSize = 30;
            $page = 1;
            $country = 'us';
            $from = $parameters['from'] ?? null;
            $to = $parameters['to'] ?? null;

            $response = $query
                ? $newsapi->getEverything($query, null, null, null, $from, $to, page_size: $pageSize, page: $page)
                : $newsapi->getTopHeadlines($query, null, $country, $category, $pageSize, $page);

            $newArticles = collect();

            if (isset($response->articles) && is_array($response->articles)) {
                foreach ($response->articles as $article) {
                    $newArticle = Article::updateOrCreate(
                        ['url' => $article->url],
                        [
                            'title' => $article->title,
                            'author' => $article->author ?? 'Unknown',
                            'description' => $article->description,
                            'source' => "News API",
                            'category' => $category ?? 'General',
                            'tags' => [$query], // Store as an array
                            'published_at' => $this->formatPublishedDate($article->publishedAt),
                        ]
                    );
                    $newArticles->push($newArticle);
                }
            }

            return $newArticles;
        } catch (Exception $e) {
            Log::error("NewsAPI Search Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search articles from The Guardian API.
     *
     * @param array $parameters
     * @return Collection|bool
     */
    public function SearchGuardianApi(array $parameters): Collection|bool
    {
        try {
            $api = new GuardianAPI(env('GUARDIAN_API_KEY'));
            $search = $parameters['search'] ?? "";
            $category = $parameters['category'] ?? "";
            $from = $parameters['from'] ?? "-30 days";
            $to = $parameters['to'] ?? "now";

            $response = $api->content()
                ->setQuery($search ?: $category)
                ->setFromDate(new \DateTimeImmutable($from))
                ->setToDate(new \DateTimeImmutable($to))
                ->setSection($category)
                ->setShowTags("contributor")
                ->setShowFields("headline,short-url,byline")
                ->setOrderBy("newest")
                ->fetch();

            $articles = $response->response->results ?? [];
            $newArticles = collect();

            foreach ($articles as $article) {
                $newArticle = Article::updateOrCreate(
                    ['url' => $article->webUrl],
                    [
                        'title' => $article->webTitle,
                        'author' => $article->fields->byline ?? 'Unknown',
                        'description' => $article->fields->description ?? 'No description available',
                        'source' => 'The Guardian',
                        'category' => $article->sectionName ?? 'General',
                        'tags' => [$article->pillarName ?? 'News'], // Store as an array
                        'published_at' => $this->formatPublishedDate($article->webPublicationDate),
                    ]
                );
                $newArticles->push($newArticle);
            }

            return $newArticles;
        } catch (Exception $e) {
            Log::error("Guardian API Search Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search articles from The New York Times API.
     *
     * @param array $parameters
     * @return Collection|bool
     */
    public function SearchNytApi(array $parameters): Collection|bool
    {
        try {
            $queryParams = [
                'q' => $parameters['search'] ?? null,
                'api-key' => env('NYT_API_KEY'),
                'page' => 0,
                'sort' => 'newest',
            ];

            if (isset($parameters['from'])) {
                $queryParams['begin_date'] = date('Ymd', strtotime($parameters['from']));
            }

            if (isset($parameters['to'])) {
                $queryParams['end_date'] = date('Ymd', strtotime($parameters['to']));
            }

            if (isset($parameters['category'])) {
                $queryParams['fq'] = "section_name:(\"{$parameters['category']}\")";
            }

            $response = Http::get("https://api.nytimes.com/svc/search/v2/articlesearch.json", $queryParams);

            if ($response->failed()) {
                Log::error("NYT API Search Failed: " . $response->status());
                return false;
            }

            $articles = $response->json('response.docs', []);
            $newArticles = collect();

            foreach ($articles as $article) {
                $newArticle = Article::updateOrCreate(
                    ['url' => $article['web_url']],
                    [
                        'title' => $article['headline']['main'] ?? 'No Title',
                        'author' => $article['byline']['original'] ?? 'Unknown',
                        'description' => $article['snippet'] ?? 'No description available',
                        'source' => 'New York Times API',
                        'category' => $article['section_name'] ?? 'General',
                        'tags' => array_column($article['keywords'] ?? [], 'value'), // Store as an array
                        'published_at' => $this->formatPublishedDate($article['pub_date'] ?? now()),
                    ]
                );
                $newArticles->push($newArticle);
            }

            return $newArticles;
        } catch (Exception $e) {
            Log::error("NYT API Search Error: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Fetch articles from The Guardian API and store them in the database.
     *
     * @return bool True if articles are fetched successfully, false otherwise.
     */
    public function fetchGuardianApi(): bool
    {
        try {
            $api = new GuardianAPI(env('GUARDIAN_API_KEY'));

            $response = $api->content()
                ->setQuery("latest")
                ->setFromDate(new \DateTimeImmutable("-1 day"))
                ->setToDate(new \DateTimeImmutable())
                ->setShowTags("contributor")
                ->setShowFields("headline,thumbnail,short-url,byline")
                ->setOrderBy("newest")
                ->fetch();

            $responseArray = json_decode(json_encode($response), true);
            $articles = $responseArray['response']['results'] ?? [];

            foreach ($articles as $article) {
                Article::updateOrCreate(
                    ['url' => $article['webUrl']],
                    [
                        'title' => $article['webTitle'],
                        'author' => $article['fields']['byline'] ?? 'Unknown',
                        'description' => null,
                        'source' => 'The Guardian',
                        'category' => $article['sectionName'] ?? 'General',
                        'tags' => json_encode([$article['pillarName'] ?? 'News']),
                        'language' => 'en',
                        'published_at' => $this->formatPublishedDate($article['webPublicationDate']),
                    ]
                );
            }

            return true;
        } catch (Exception $e) {
            Log::error("Guardian API Fetch Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch articles from The New York Times API and store them in the database.
     *
     * @return bool True if articles are fetched successfully, false otherwise.
     */
    private function fetchNytApi(): bool
    {
        try {
            $response = Http::get("https://api.nytimes.com/svc/mostpopular/v2/viewed/7.json", [
                'api-key' => env('NYT_API_KEY'),
            ]);

            if ($response->failed()) {
                return false;
            }

            $articles = $response->json()['results'] ?? [];

            foreach ($articles as $article) {
                $publishedAt = $this->formatPublishedDate($article['published_date'] ?? now());

                Article::updateOrCreate(
                    ['url' => $article['url']],
                    [
                        'title' => $article['title'],
                        'author' => $article['byline'] ?? 'Unknown',
                        'description' => $article['abstract'],
                        'source' => 'New York Times API',
                        'category' => $article['section'] ?? 'General',
                        'tags' => json_encode($article['des_facet'] ?? ['News']),
                        'language' => 'en',
                        'published_at' => $publishedAt,
                    ]
                );
            }

            return true;
        } catch (Exception $e) {
            Log::error("NYT API Fetch Error: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Fetch articles from all news sources.
     *
     * @return bool True if all sources were fetched successfully, false otherwise.
     */
    public function fetchAllNews(): bool
    {
        $newsApiSuccess = $this->fetchNewsApi();
        $guardianSuccess = $this->fetchGuardianApi();
        $nytSuccess = $this->fetchNytApi();

        return $newsApiSuccess && $guardianSuccess && $nytSuccess;
    }

    public function allSearchApi(array $params)
    {
        $source = null;
        if (Arr::hasAny($params, 'source')) {
            $source = $params['source'];
        } else {
            $source = "all";
        }
        if ($source === "all") {
            // Sort by latest published articles
            $newsapi = $this->SearchNewsApi($params);
            $guardian = $this->SearchGuardianApi($params);
            $nyt = $this->SearchNytApi($params);
            $result = collect();
            if ($newsapi) {
                $result = $result->merge($newsapi);
            }
            if ($guardian) {
                $result = $result->merge($guardian);
            }
            if ($nyt) {
                $result = $result->merge($nyt);
            }
            return $result->sortByDesc('published_at');
        }

        if ($source === "News API") {
            return $this->SearchNewsApi($params);
        }

        if ($source === "The Guardian") {
            return $this->SearchGuardianApi($params);
        }

        if ($source === "New York Times API") {
            return $this->SearchNytApi($params);
        }


    }

    /**
     * Format the published date to a MySQL-compatible format.
     *
     * @param string|null $publishedAt The original published date.
     * @return string Formatted date string.
     */
    private function formatPublishedDate($publishedAt): string
    {
        return $publishedAt ? Carbon::parse($publishedAt)->toDateTimeString() : now();
    }
}
