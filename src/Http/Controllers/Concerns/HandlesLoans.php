<?php

namespace Blax\Shop\Http\Controllers\Concerns;

use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Http\Requests\StoreLoanRequest;
use Blax\Shop\Http\Resources\LoanResource;
use Blax\Shop\Models\ProductPurchase;
use Blax\Workkit\Services\MiscService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drop this trait on a host controller to expose the full loan lifecycle —
 * list / create / show / extend / return — without re-implementing any of
 * the standard machinery (atomic stock decrement, ownership guard, event
 * dispatch, status filtering, pagination, resource envelopes).
 *
 * Required override:
 *   - {@see loanableModel()}  — return the host model class name (e.g.
 *                                Book::class). Must implement Cartable +
 *                                Purchasable; usually a {@see \Blax\Shop\Models\Product}
 *                                subclass using {@see \Blax\Shop\Traits\IsLoanableProduct}.
 *
 * Optional overrides:
 *   - {@see loanResource()}    — the JsonResource subclass to serialise
 *                                ProductPurchase rows. Defaults to
 *                                {@see LoanResource}; override to point at
 *                                a host subclass that customises the
 *                                purchasable resource (e.g. BookResource).
 *   - {@see storeRequest()}    — FormRequest class used by store(). Defaults
 *                                to {@see StoreLoanRequest} which validates
 *                                a `loanable_id` field. Override if you
 *                                want the body key to be `book_id` etc.
 *
 * Wire endpoints in your routes file (per-method) or use the
 * `Route::shopLoans()` macro to register all five at once.
 */
trait HandlesLoans
{
    /** Required — name of the Cartable+Purchasable model being loaned. */
    abstract protected function loanableModel(): string;

    /** Override to customise the response shape (purchasable subresource). */
    protected function loanResource(): string
    {
        return LoanResource::class;
    }

    /** Override to swap the FormRequest used by store(). */
    protected function storeRequest(): string
    {
        return StoreLoanRequest::class;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ProductPurchase::query()
            ->where('purchaser_type', $user::class)
            ->where('purchaser_id', $user->getKey())
            ->where('purchasable_type', $this->loanableModel())
            ->with('purchasable')
            ->orderByDesc('from');

        match ($request->query('status')) {
            'active' => $query->activeLoans(),
            'returned' => $query->returned(),
            'overdue' => $query->overdue(),
            default => null,
        };

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return response()->json(MiscService::apiPaginated(
            $query->paginate($perPage),
            $this->loanResource(),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        // Resolve the request class lazily so subclass overrides win.
        /** @var StoreLoanRequest $validated */
        $validated = app($this->storeRequest());
        $validated->setContainer(app())->setRedirector(app('redirect'));
        $validated->initialize(
            $request->query(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $request->getContent(),
        );
        $validated->setUserResolver($request->getUserResolver());
        $validated->validateResolved();

        $model = $this->loanableModel();
        /** @var \Blax\Shop\Models\Product $item */
        $item = $model::query()->findOrFail($validated->loanableId());

        try {
            $purchase = $item->checkOutTo($request->user());
        } catch (NotEnoughStockException) {
            throw ValidationException::withMessages([
                $validated->validationKey() => ['No copies of this item are currently available.'],
            ]);
        }

        return response()->json(
            MiscService::apiItem($purchase->load('purchasable'), $this->loanResource()),
            Response::HTTP_CREATED,
        );
    }

    public function show(Request $request, ProductPurchase $purchase): JsonResponse
    {
        $this->ensureLoanOwner($request, $purchase);

        return response()->json(MiscService::apiItem(
            $purchase->load('purchasable'),
            $this->loanResource(),
        ));
    }

    public function extend(Request $request, ProductPurchase $purchase): JsonResponse
    {
        $this->ensureLoanOwner($request, $purchase);

        if ($purchase->isReturned()) {
            throw ValidationException::withMessages([
                'loan' => ['This loan has already been returned.'],
            ]);
        }

        if (! $purchase->canExtend()) {
            $message = $purchase->isOverdue()
                ? 'Overdue loans cannot be extended — please return the item first.'
                : 'This loan has reached the maximum number of extensions.';

            throw ValidationException::withMessages(['loan' => [$message]]);
        }

        $purchase->extend();

        return response()->json(MiscService::apiItem(
            $purchase->load('purchasable'),
            $this->loanResource(),
        ));
    }

    public function returnLoan(Request $request, ProductPurchase $purchase): JsonResponse
    {
        $this->ensureLoanOwner($request, $purchase);

        if ($purchase->isReturned()) {
            throw ValidationException::withMessages([
                'loan' => ['This loan has already been returned.'],
            ]);
        }

        $purchase->markReturned();

        $purchase->load('purchasable');
        if ($purchase->purchasable !== null && method_exists($purchase->purchasable, 'increaseStock')) {
            $purchase->purchasable->increaseStock(1);
        }

        return response()->json(MiscService::apiItem($purchase, $this->loanResource()));
    }

    /**
     * 403 unless the authenticated user is the loan's purchaser.
     */
    protected function ensureLoanOwner(Request $request, ProductPurchase $purchase): void
    {
        $user = $request->user();

        $isOwner = $purchase->purchaser_type === $user::class
            && (string) $purchase->purchaser_id === (string) $user->getKey();

        if (! $isOwner) {
            abort(Response::HTTP_FORBIDDEN, 'This loan does not belong to you.');
        }
    }
}
