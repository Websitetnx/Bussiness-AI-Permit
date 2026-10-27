<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Business Permit Certificate', $canManageApplications ? 'review' : 'track');
?>
<div class="certificate-toolbar">
  <a class="button button-secondary" href="<?= e(url($backPath)) ?>">&larr; Back to application</a>
  <button class="button" type="button" onclick="window.print()">Print / Save as PDF</button>
</div>

<article class="certificate-card" aria-labelledby="certificateTitle">
  <div class="certificate-frame">
    <header class="certificate-header">
      <img src="<?= e(url('frontend/assets/logo-light.png')) ?>" alt="PERMIT">
      <div>
        <p>Republic of the Philippines</p>
        <h2><?= e($lguName) ?></h2>
        <span>Business Permits and Licensing Office</span>
      </div>
    </header>

    <div class="certificate-heading">
      <p>Official Electronic Record</p>
      <h1 id="certificateTitle"><?= e($certificateType) ?></h1>
      <span class="certificate-validity <?= $isExpired ? 'expired' : '' ?>"><?= $isExpired ? 'Expired record' : ($notYetEffective ? 'Not yet effective' : 'Valid permit') ?></span>
    </div>

    <section class="certificate-declaration">
      <p>This is to certify that</p>
      <strong><?= e($certificate['business_name']) ?></strong>
      <p>owned and operated by <b><?= e($certificate['owner_name']) ?></b>, with business address at</p>
      <address><?= nl2br(e($certificate['address'])) ?></address>
      <p>is authorized to operate as a <b><?= e($certificate['business_type']) ?></b>, subject to applicable national and local laws, ordinances, and permit conditions.</p>
    </section>

    <dl class="certificate-details">
      <div><dt>Business permit no.</dt><dd><?= e($certificate['permit_number']) ?></dd></div>
      <div><dt>Application reference</dt><dd><?= e($certificate['reference']) ?></dd></div>
      <div><dt>Organization</dt><dd><?= e($certificate['organization_type']) ?></dd></div>
      <div><dt>Tax identification no.</dt><dd><?= e($certificate['tin']) ?></dd></div>
      <div><dt>Date issued</dt><dd><time datetime="<?= e($issuedAt->format('Y-m-d')) ?>"><?= e($issuedAt->format('F j, Y')) ?></time></dd></div>
      <div><dt>Effective date</dt><dd><time datetime="<?= e($effectiveAt->format('Y-m-d')) ?>"><?= e($effectiveAt->format('F j, Y')) ?></time></dd></div>
      <div><dt>Valid until</dt><dd><time datetime="<?= e($validUntil->format('Y-m-d')) ?>"><?= e($validUntil->format('F j, Y')) ?></time></dd></div>
    </dl>

    <footer class="certificate-footer">
      <div class="certificate-issued-by">
        <span>Released by</span>
        <strong><?= e($release['released_by_name'] ?? 'Authorized BPLO Officer') ?></strong>
        <small>Authorized releasing officer</small>
      </div>
      <div class="certificate-verification">
        <?php if ($verificationUrl): ?>
        <div class="permit-qr" data-permit-verification="<?= e($verificationUrl) ?>"></div>
        <a href="<?= e($verificationUrl) ?>">Verify current permit status</a>
        <?php else: ?><p>Online verification link unavailable. Contact the issuing BPLO.</p><?php endif; ?>
        <strong>System-generated certificate</strong>
        <p>Verify this record in PERMIT using permit number <b><?= e($certificate['permit_number']) ?></b> or application reference <b><?= e($certificate['reference']) ?></b>.</p>
      </div>
    </footer>
  </div>
</article>
<script src="<?= e(url('frontend/assets/vendor/qrcode.js')) ?>"></script>
<script src="<?= e(url('frontend/assets/permit-qr.js')) ?>"></script>
<?php render_app_footer(); ?>
