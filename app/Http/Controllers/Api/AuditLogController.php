<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Rate;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $logs = AuditLog::query()
            // Le tarif est désigné par sa catégorie, sa zone et sa version.
            ->with(['user', 'auditable' => fn (MorphTo $morph) => $morph->morphWith([Rate::class => ['category', 'zone', 'version']])])
            ->when($request->filled('action'), fn ($query) => $query->where('action', 'like', $request->string('action').'%'))
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->latest('id')
            ->paginate(50);

        return AuditLogResource::collection($logs);
    }
}
