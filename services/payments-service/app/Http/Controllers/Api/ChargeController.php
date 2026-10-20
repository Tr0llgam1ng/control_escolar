<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\DocumentType;
use App\Payments\ChargeService;
use App\Payments\Money;
use App\Payments\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contract: Payments Service v2 — references
 *  GET   /api/document-types                     any user (catalog of paperwork and prices)
 *  POST  /api/charges                            student: {document_type_id}; admin: + {student_id}
 *  GET   /api/charges                            admin (filters: status, method, new, expired, student_id, q)
 *  GET   /api/charges/summary                    admin (counters for the dashboard)
 *  GET   /api/charges/{id}                       admin | owner student (with payments and history)
 *  GET   /api/charges/by-reference/{reference}   admin | owner student
 *  GET   /api/charges/{id}/status                internal service only
 *  PATCH /api/charges/{id}/status                admin: {status: approved|rejected|pending, reason?}
 *  POST  /api/charges/{id}/review                admin: mark as checked
 *  GET   /api/students/{studentId}/charges       admin | owner student
 */
class ChargeController extends Controller
{
    public function documentTypes(): JsonResponse
    {
        return response()->json(['data' => DocumentType::where('is_active', true)->orderBy('price')->get()]);
    }

    public function store(Request $request, ChargeService $service): JsonResponse
    {
        $this->requireRole($request, 'student', 'admin');
        $isAdmin = $this->actor($request)['role'] === 'admin';

        $data = $request->validate([
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'student_id' => [$isAdmin ? 'required' : 'prohibited', 'integer', 'min:1'],
        ], [
            'document_type_id.required' => 'Selecciona el documento que necesitas.',
            'document_type_id.exists' => 'El documento seleccionado no está disponible.',
            'student_id.prohibited' => 'No puedes generar referencias para otro alumno.',
        ]);

        $studentId = $isAdmin ? (int) $data['student_id'] : $this->ownStudentId($request);
        [$charge, $created] = $service->create($studentId, DocumentType::findOrFail($data['document_type_id']), $this->auditActor($request));

        return response()->json($charge->load('documentType'), $created ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Charge::STATUSES)],
            'method' => ['nullable', Rule::in([Charge::CARD, Charge::CASH, 'none'])],
            'new' => ['nullable', 'boolean'],
            'expired' => ['nullable', 'boolean'],
            'student_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:20'],
        ]);

        $query = Charge::with('documentType')->orderByRaw('reviewed_at is not null')->orderByDesc('id');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['method'])) {
            $filters['method'] === 'none' ? $query->whereNull('payment_method') : $query->where('payment_method', $filters['method']);
        }
        if (! empty($filters['new'])) {
            $query->whereNull('reviewed_at');
        }
        if (isset($filters['expired'])) {
            $filters['expired']
                ? $query->where('status', Charge::PENDING)->where('expires_at', '<=', now())
                : $query->where('expires_at', '>', now());
        }
        if (isset($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }
        if (! empty($filters['q'])) {
            $query->where('reference', 'like', preg_replace('/\D/', '', $filters['q']).'%');
        }

        return response()->json(['data' => $query->limit(500)->get()]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $approved = Charge::where('status', Charge::APPROVED)->get();

        return response()->json([
            'new' => Charge::whereNull('reviewed_at')->count(),
            'pending' => Charge::where('status', Charge::PENDING)->where('expires_at', '>', now())->count(),
            'expired' => Charge::where('status', Charge::PENDING)->where('expires_at', '<=', now())->count(),
            'approved' => $approved->count(),
            'rejected' => Charge::where('status', Charge::REJECTED)->count(),
            'collected' => Money::fromCents($approved->sum(fn (Charge $c) => $c->amountCents())),
            'currency' => config('payments.currency', 'MXN'),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $charge = Charge::with(['documentType', 'transactions', 'auditLogs'])->find($id);
        abort_if(! $charge, 404, 'La referencia de pago no existe.');
        $this->requireOwnerOrAdmin($request, $charge->student_id);

        return response()->json($charge);
    }

    public function byReference(Request $request, string $reference): JsonResponse
    {
        // Format + Luhn first: a mistyped reference never reaches the database.
        abort_unless(ReferenceGenerator::isValid($reference), 422, 'La referencia no es válida.');

        $charge = Charge::with(['documentType', 'transactions'])->where('reference', $reference)->first();
        abort_if(! $charge, 404, 'No existe una referencia con ese número.');
        $this->requireOwnerOrAdmin($request, $charge->student_id);

        return response()->json($charge);
    }

    /** For other services: is this reference paid? */
    public function status(Request $request, int $id): JsonResponse
    {
        $this->requireInternal($request);
        $charge = Charge::find($id);
        abort_if(! $charge, 404, 'La referencia de pago no existe.');

        return response()->json([
            'id' => $charge->id,
            'reference' => $charge->reference,
            'student_id' => $charge->student_id,
            'status' => $charge->status,
            'paid' => $charge->status === Charge::APPROVED,
            'paid_at' => $charge->paid_at?->toIso8601String(),
        ]);
    }

    public function changeStatus(Request $request, ChargeService $service, int $id): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $data = $request->validate([
            'status' => ['required', Rule::in(Charge::STATUSES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['status.in' => 'Estado inválido. Usa: aprobado, rechazado o pendiente.']);

        $charge = $service->changeStatus($id, $data['status'], $data['reason'] ?? null, $this->auditActor($request));

        return response()->json($charge->load(['documentType', 'transactions', 'auditLogs']));
    }

    public function review(Request $request, ChargeService $service, int $id): JsonResponse
    {
        $this->requireRole($request, 'admin');

        return response()->json($service->review($id, $this->auditActor($request))->load(['documentType', 'transactions', 'auditLogs']));
    }

    public function forStudent(Request $request, int $studentId): JsonResponse
    {
        $this->requireOwnerOrAdmin($request, $studentId);

        $charges = Charge::with(['documentType', 'transactions'])
            ->where('student_id', $studentId)
            ->orderByDesc('id')
            ->get();

        $sum = fn ($items) => Money::fromCents($items->sum(fn (Charge $c) => $c->amountCents()));

        return response()->json([
            'student_id' => $studentId,
            'summary' => [
                'pending_total' => $sum($charges->filter(fn (Charge $c) => $c->isPayable())),
                'approved_total' => $sum($charges->where('status', Charge::APPROVED)),
                'currency' => config('payments.currency', 'MXN'),
            ],
            'data' => $charges->values(),
        ]);
    }
}
