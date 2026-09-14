<?php
require_once __DIR__ . '/../../../../Include/Header.php';
?>
<div class="container-xl">
    <div class="page-header d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title">MOS-GOV</h2>
                <div class="text-secondary">Church governance layer · V0.1</div>
            </div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Governance dashboard</h3>
                </div>
                <div class="card-body">
                    <p>
                        MOS-GOV stores governance-specific data separately from
                        ChurchCRM core data.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-secondary">Structures</div>
                                    <div class="h2 mb-0">—</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-secondary">Bodies</div>
                                    <div class="h2 mb-0">—</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-secondary">Open issues</div>
                                    <div class="h2 mb-0">—</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-secondary">Open tasks</div>
                                    <div class="h2 mb-0">—</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-4">
                        V0.1 scaffold is installed as a boundary-safe starting
                        point. CRUD screens and ChurchCRM person lookup are
                        added only after runtime verification.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../../../Include/Footer.php'; ?>
