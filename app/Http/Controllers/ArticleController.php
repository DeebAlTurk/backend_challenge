<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Services\NewsAggregatorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Psy\Util\Str;

class ArticleController extends Controller
{
    /**
     * Get all articles with filtering options.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */

    public function index(Request $request)
    {
        // Define the validation rules
        $rules = [
            'search' => 'required|string',
            'category' => 'nullable|string',
            'source' => [
                'nullable',
                Rule::in(['News API', 'The Guardian', 'New York Times API']),
            ],
            'tags' => 'nullable|string',
            'author' => 'nullable|string',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ];

        $params = [
            'search',
            'category',
            'source',
            'tags',
            'from',
            'author',
            'to'
        ];

        // Validate the incoming request data
        $validator = Validator::make($request->only($params), $rules);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $query = Article::query();

        // Apply the validated filters to the query
        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }
        if ($request->filled('author')) {
            $query->where('author', 'like', '%' . $request->author . '%');
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('tags')) {
            // Apply OR conditions to check if any of the tags match
            foreach (explode(',', $request->tags) as $tag) {
                $query->orWhereJsonContains('tags',\Illuminate\Support\Str::title($tag));
            }
        }
        if ($request->filled('from')) {
            $fromDate = Carbon::parse($request->from)->startOfDay();
            $query->where('published_at', '>=', $fromDate);
        }
        if ($request->filled('to')) {
            $toDate = Carbon::parse($request->to)->endOfDay();
            $query->where('published_at', '<=', $toDate);
        }

        // Check if any records match before generating pagination
        $count = $query->count();
        if ($count === 0) {
           $newsAggregator= new NewsAggregatorService();
            // Merge all collections into one
            $mergedArticles = $newsAggregator->allSearchApi($validator->getData());
            // Filter by author if the request has an 'author' parameter
            if ($request->filled('author')) {
                $authorSearch = strtolower($request->author); // Convert to lowercase for case-insensitive search
                $mergedArticles = $mergedArticles->filter(fn($article) => stripos($article->author, $authorSearch) !== false);
            }
            return $this->paginateCollection($mergedArticles, $request->get('per_page', 10));
        }


        // If there are results, return paginated results
        return response()->json($query->orderBy('published_at', 'desc')->paginate(10));
    }


    private function paginateCollection( $items, $perPage)
    {
        $page = request()->get('page', 1); // Get current page from request
        $total = $items->count(); // Total items count

        $pagedData = $items->slice(($page - 1) * $perPage, $perPage)->values(); // Get current page items

        return new LengthAwarePaginator(
            $pagedData,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }


}
