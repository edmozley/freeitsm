<?php
require_once __DIR__ . '/../../../includes/service_context.php';   // ServiceError, thrown below (GH #288)
/**
 * Assets -> Leases (3.5.0): The leases one asset has been on, for the asset panel.
 * Rules in includes/leases.php.
 */
require __DIR__ . '/_boot.php';
requireModuleAccessJson('assets');   // also inside leaseEndpoint(); here so the endpoint audit (D005) can see it

leaseEndpoint('GET', function (PDO $conn, ActorContext $ctx, array $in) {
    $assetId = (int)($in['asset_id'] ?? 0);
    if (!analystCanAccessAsset($conn, $ctx->actorId, $assetId)) throw new ServiceError('not_found', 'not_found', 'Asset not found.');
    return ['leases' => leaseHistoryForAsset($conn, $ctx->actorId, $assetId)];
});
