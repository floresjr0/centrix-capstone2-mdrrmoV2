<?php
/**
 * Coordinator-only offline bootstrap. Include from coordinator pages only.
 *
 * Expected variables:
 *   $centerId (optional int)
 *   $coordOfflinePage (optional string: 'registrations')
 */
$coordCenterId = isset($centerId) ? (int)$centerId : 0;
$coordOfflinePage = isset($coordOfflinePage) ? (string)$coordOfflinePage : '';
$coordJsVer = (int)@filemtime(__DIR__ . '/../asset/js/coordinator/coordinator_walkin_offline.js');
$coordDbVer = (int)@filemtime(__DIR__ . '/../asset/js/coordinator/coordinator_offline_db.js');
$coordRegVer = (int)@filemtime(__DIR__ . '/../asset/js/coordinator/coordinator_registrations_offline.js');
?>
<link rel="stylesheet" href="../asset/css/coordinator_offline.css">
<div class="coord-offline-bar" id="coordOfflineBar" aria-live="polite">
    <div class="coord-offline-left">
        <span class="coord-offline-dot offline" aria-hidden="true"></span>
        <span class="coord-offline-msg"><strong>Checking connection…</strong></span>
    </div>
</div>
<script>
window.MDRRMO_COORDINATOR = {
    centerId: <?php echo $coordCenterId; ?>,
    apiBase: '../api/coordinator/'
};
</script>
<script src="../asset/js/coordinator/coordinator_offline_db.js?v=<?php echo $coordDbVer; ?>"></script>
<script src="../asset/js/coordinator/coordinator_walkin_offline.js?v=<?php echo $coordJsVer; ?>"></script>
<?php if ($coordOfflinePage === 'registrations'): ?>
<script src="../asset/js/coordinator/coordinator_registrations_offline.js?v=<?php echo $coordRegVer; ?>"></script>
<?php endif; ?>
