<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PDU & Depoint Procurement Management System</title>
  <link rel="icon" type="image/jpeg" href="logo.jpeg">
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --PDU-navy: #0f2a4a;
      --PDU-gold: #d4af37;
      --Depoint-blue: #0d6efd;
    }
    body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .bg-PDU { background-color: var(--PDU-navy) !important; }
    .text-PDU { color: var(--PDU-navy) !important; }
    .border-PDU { border-color: var(--PDU-navy) !important; }
    
    .login-card { border-top: 5px solid var(--PDU-gold); border-radius: 12px; }
    
    .timeline-steps { display: flex; justify-content: space-between; position: relative; margin: 20px 0; }
    .timeline-steps::before { content: ""; position: absolute; top: 18px; left: 0; width: 100%; height: 4px; background: #e0e0e0; z-index: 1; }
    .step-item { position: relative; z-index: 2; background: #fff; padding: 0 5px; text-align: center; width: 18%; }
    .step-icon { width: 36px; height: 36px; border-radius: 50%; background: #e0e0e0; color: #6c757d; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: bold; border: 2px solid #fff; }
    .step-item.completed .step-icon { background: #198754; color: white; }
    .step-item.active .step-icon { background: var(--Depoint-blue); color: white; box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.2); }
    .step-item.delayed .step-icon { background: #dc3545; color: white; box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.2); }
    .step-title { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
    .step-party { font-size: 0.68rem; font-weight: 700; color: var(--PDU-navy); }
    .step-date { font-size: 0.7rem; color: #6c757d; }

    .notification-badge { position: relative; display: inline-block; }
    .notification-badge .badge { position: absolute; top: -5px; right: -5px; }
    .audit-change-list > div { margin-bottom: 0.35rem; }

    @media print {
      .no-print { display: none !important; }
      .card { border: none !important; box-shadow: none !important; }
    }
  </style>
</head>
<body>

  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-PDU shadow-sm no-print">
    <div class="container-fluid px-4">
      <a class="navbar-brand d-flex align-items-center gap-2" href="#">
        <i class="bi bi-diagram-3-fill text-warning fs-4"></i>
        <span class="fw-bold">PDU Biding Tracker</span>
        <span class="badge bg-warning text-dark ms-2" id="userRoleBadge">Guest</span>
      </a>
      <div class="d-flex align-items-center gap-3">
        <!-- Notification Center -->
        <button class="btn btn-outline-light btn-sm position-relative d-none" id="notificationBtn" data-bs-toggle="modal" data-bs-target="#notificationModal">
          <i class="bi bi-bell-fill"></i>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notifCount">0</span>
        </button>
        <span class="text-light small" id="userInfo">Not Logged In</span>
        <button class="btn btn-outline-light btn-sm d-none" id="logoutBtn" onclick="logout()">
          <i class="bi bi-box-arrow-right"></i> Logout
        </button>
      </div>
    </div>
  </nav>

  <div class="container-fluid px-4 py-4">

    <!-- 1. LOGIN SCREEN WITH PHONE NUMBER & PASSWORD -->
    <div id="loginScreen" class="row justify-content-center py-5">
      <div class="col-md-5 col-lg-4">
        <div class="card shadow login-card p-4">
          <div class="text-center mb-4">
            <i class="bi bi-shield-lock-fill text-PDU display-4"></i>
            <h4 class="fw-bold text-PDU mt-2">Portal Access</h4>
            <p class="text-muted small">Sign in using Email & Password</p>
          </div>
          
          <div id="loginAlert" class="alert alert-danger d-none small"></div>

          <form onsubmit="handleLogin(event)">
            <div class="mb-3">
              <label class="form-label font-weight-bold" for="loginEmail">Email Address</label>
              <input type="email" class="form-control" id="loginEmail" name="email" autocomplete="username" required>
            </div>
            <div class="mb-4">
              <label class="form-label">Password</label>
              <input type="password" class="form-control" id="loginPassword" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary bg-PDU border-PDU w-100 py-2 fw-bold">
              Sign In
            </button>
          </form>
          <!-- <div class="mt-3 text-center">
            <small class="text-muted">Default Demo Login: <br> <strong>Username:</strong> user1 | <strong>Pass:</strong> password123</small>
          </div> -->
        </div>
      </div>
    </div>

    <!-- 2. MAIN DASHBOARD SCREEN -->
    <div id="dashboardScreen" class="d-none">
      
      <!-- Top Actions Bar -->
      <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
          <h3 class="fw-bold mb-0" id="welcomeHeading">Procurement Dashboard</h3>
          <p class="text-muted small mb-0">Track procurement stages, statutory stage timelines, delays, responsible parties, and notifications</p>
        </div>
        <div class="d-flex gap-2">
          <!-- Button accessible to BOTH PDU & Depoint -->
          <button class="btn btn-primary" id="newProcurementBtn" data-bs-toggle="modal" data-bs-target="#newProcurementModal">
            <i class="bi bi-plus-circle me-1"></i> Initiate Record
          </button>
          <!-- User Management Button (PDU ONLY) -->
          <button class="btn btn-warning text-dark d-none" id="manageUsersBtn" data-bs-toggle="modal" data-bs-target="#userMgmtModal">
            <i class="bi bi-people-fill me-1"></i> User Management
          </button>
          <!-- Audit Report Button -->
          <button class="btn btn-success" id="generateReportBtn" onclick="switchTab('reports')">
            <i class="bi bi-file-earmark-bar-graph me-1"></i> Compliance Reports
          </button>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <ul class="nav nav-tabs mb-4 no-print" id="mainTabs">
        <li class="nav-item">
          <a class="nav-link active" id="tab-tracker" href="#" onclick="switchTab('tracker')">Procurement Tracker</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" id="tab-reports" href="#" onclick="switchTab('reports')">PDU Audit & Compliance Reports</a>
        </li>
      </ul>

      <!-- TAB 1: TRACKER LIST -->
      <div id="trackerTabContent">
        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
          <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-primary border-4">
              <span class="text-muted small fw-semibold">Total Records</span>
              <h3 class="fw-bold my-1" id="kpiTotal">0</h3>
              <span class="text-muted extra-small">Across all entities</span>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-warning border-4">
              <span class="text-muted small fw-semibold">On Track</span>
              <h3 class="fw-bold my-1 text-warning" id="kpiActive">0</h3>
              <span class="text-muted extra-small">Within statutory timeframe</span>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-danger border-4">
              <span class="text-muted small fw-semibold">Delayed Records</span>
              <h3 class="fw-bold my-1 text-danger" id="kpiDelayed">0</h3>
              <span class="text-muted extra-small">Exceeded stage timeline target</span>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-success border-4">
              <span class="text-muted small fw-semibold">Completed</span>
              <h3 class="fw-bold my-1 text-success" id="kpiCompleted">0</h3>
              <span class="text-muted extra-small">Contracts awarded</span>
            </div>
          </div>
        </div>

        <!-- Master Table -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-3">
            <h5 class="fw-bold mb-0">Master Procurement Records</h5>
            <div class="input-group input-group-sm" style="max-width: 360px;">
              <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
              <input type="search" class="form-control" id="procurementSearch" aria-label="Search procurement records" placeholder="Ref no., type, method, or initiator" oninput="renderDashboard()">
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Ref No.</th>
                    <th>Procurement Type</th>
                    <!-- <th>Entity</th> -->
                    <th>Method</th>
                    <th>Initiation Date</th>
                    <th>BEB Notice Date</th>
                    <th>Current Delay</th>
                    <th>Current Status</th>
                    <th>Initiated By</th>
                    <th>Assigned To</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody id="procurementTableBody">
                  <!-- Rendered dynamically -->
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 2: AUDIT & REPORTS -->
      <div id="reportsTabContent" class="d-none">
        <div class="card border-0 shadow-sm p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h4 class="fw-bold text-PDU">PDU Statutory Monitoring & Compliance Audit</h4>
              <p class="text-muted small">Comprehensive timeline report detailing BEB Notice Dates, Projected Completion, Responsible Parties, Delays, and Statuses</p>
            </div>
            <button class="btn btn-outline-dark btn-sm no-print" onclick="window.print()">
              <i class="bi bi-printer me-1"></i> Print / Export Report
            </button>
          </div>

          <!-- Report Filters -->
          <div class="row g-3 mb-4 bg-light p-3 rounded no-print">
            <div class="col-md-4">
              <label class="form-label small fw-bold">Filter Type</label>
              <select class="form-select form-select-sm" id="filterType" onchange="renderReports()">
                <option value="ALL">All Types</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-bold">Filter Method</label>
              <select class="form-select form-select-sm" id="filterMethod" onchange="renderReports()">
                <option value="ALL">All Methods</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-bold">Filter Status</label>
              <select class="form-select form-select-sm" id="filterStatus" onchange="renderReports()">
                <option value="ALL">All Statuses</option>
                <option value="On Track">On Track</option>
                <option value="Delayed">Delayed</option>
                <option value="Completed">Completed</option>
              </select>
            </div>
          </div>

          <!-- Report Table -->
          <div class="table-responsive">
            <table class="table table-bordered align-middle">
              <thead class="table-secondary">
                <tr>
                  <th>Ref No.</th>
                  <th>Type</th>
                  <!-- <th>Entity</th> -->
                  <th>Method</th>
                  <th>Initiation Date</th>
                  <th>BEB Notice Date</th>
                  <th>Projected Completion</th>
                  <th>Delay (Days)</th>
                  <th>Current Status</th>
                  <th>Regulatory Flag</th>
                </tr>
              </thead>
              <tbody id="reportTableBody">
                <!-- Rendered dynamically -->
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- MODAL 1: CREATE NEW RECORD WITH AUTOMATIC TYPE -> METHOD -> STAGES -->
  <div class="modal fade" id="newProcurementModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header bg-PDU text-white">
          <h5 class="modal-title fw-bold">Initiate Procurement Record (PDU Statutory Periods)</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form onsubmit="createProcurement(event)">
          <div class="modal-body">
            <div class="alert alert-danger d-none" id="createProcurementAlert" role="alert"></div>
            <div class="row g-3 mb-3">
              <div class="col-md-3">
                <label class="form-label fw-bold">Procurement Category/Type</label>
                <select class="form-select" id="pType" onchange="updateMethodDropdown(); loadPresetDefaults();" required>
                  <option value="">Select Type</option>
                </select>
              </div>
               <div class="col-md-3">
                <label class="form-label fw-bold">Procurement Method</label>
                <select class="form-select" id="pMethod" onchange="loadPresetDefaults()" required>
                  <!-- Populated dynamically based on Procurement Type -->
                </select>
              </div>

              <div class="col-md-2">
                <label class="form-label fw-bold">Initiation Date</label>
                <input type="date" class="form-control" id="pStartDate" readonly  onchange="calculateCustomTimeline()" required>
              </div>
              
              <div class="col-md-4">
                <label class="form-label fw-bold">Procurement Reference No.</label>
                <input type="text" class="form-control" id="pRef" required>
              </div>
             
              <div class="col-md-5">
                <label class="form-label fw-bold">Subject / Description</label>
                <input type="text" class="form-control" id="pTitle" required>
              </div>
              
              <div class="col-md-3">
                <label class="form-label fw-bold">Initiated By</label>
                <input type="text" class="form-control bg-light" id="pInitiator" readonly>
              </div>

               <div class="col-md-3">
                <label class="form-label fw-bold">Agent</label>
                <select class="form-select" id="pAgent" required>
                  <option value="">Select Agent</option>
                </select>
              </div>
            </div>

            <!-- Stage Customization Grid -->
            <div class="card border mb-3">
              <div class="card-header bg-light fw-bold text-PDU d-flex justify-content-between align-items-center">
                <span><i class="bi bi-sliders me-1"></i> PDU Stage Timeframe & Responsible Party Configuration</span>
                <span class="badge bg-secondary text-white" id="statutoryRuleLabel">Statutory Rule Applied</span>
              </div>
              <div class="card-body p-2">
                <div class="table-responsive">
                  <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-secondary">
                      <tr>
                        <th>Stage Name</th>
                        <th style="width: 140px;">Days Allowance</th>
                        <th style="width: 230px;">Responsible Party</th>
                        <th>Calculated Target Date</th>
                      </tr>
                    </thead>
                    <tbody id="stageInputsTableBody">
                      <!-- Dynamically rendered inputs for statutory stages -->
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- Calculated Output Summary -->
            <div class="row g-3 d-none">
              <div class="col-md-6">
                <label class="form-label fw-bold text-primary">Calculated BEB Notice Date</label>
                <input type="text" class="form-control bg-light fw-bold text-primary" id="pBebDatePreview" readonly placeholder="Auto-calculated">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold text-success">Projected Completion Date</label>
                <input type="text" class="form-control bg-light fw-bold text-success" id="pEndDatePreview" readonly placeholder="Auto-calculated">
              </div>
            </div>

          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary bg-PDU">Save Procurement Record</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- MODAL 2: USER MANAGEMENT WITH PHONE NUMBERS (PDU Admin ONLY) -->
  <div class="modal fade" id="userMgmtModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-1"></i>System User Management</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <!-- Add User Form -->
          <form onsubmit="createUser(event)" class="row g-3 mb-4 p-3 border rounded bg-light">
            <h6 class="fw-bold mb-0">Add New System User</h6>
            <div class="col-md-3">
              <label class="form-label small fw-bold">Full Name</label>
              <input type="text" class="form-control form-control-sm" id="newDisplayName" maxlength="50" required>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-bold">Phone Number</label>
              <input type="tel" class="form-control form-control-sm" id="newPhone" placeholder="+256..." required>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-bold">Password</label>
              <input type="password" class="form-control form-control-sm" id="newPassword" required>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-bold">Role</label>
              <select class="form-select form-select-sm" id="newRole" required>
                <option value="user">User</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label small fw-bold">Entity</label>
              <select class="form-select form-select-sm" id="newEntity" required>
                <option value="Depoint">Depoint</option>
                <option value="PDU">PDU</option>
              </select>
            </div>
            <div class="col-md-7">
              <label class="form-label small fw-bold">Email</label>
              <input type="email" class="form-control form-control-sm" id="newEmail" >
            
            </div>
           
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-sm btn-dark">Register User</button>
            </div>
          </form>

          <!-- Registered Users Table -->
          <h6 class="fw-bold">Active System Accounts</h6>
          <div class="table-responsive">
            <table class="table table-sm table-striped border">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Phone Number</th>
                  <th>Email</th>
                  <th>Entity</th>
                  <th>Role</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="userListTableBody">
                <!-- Dynamic User Rows -->
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: EDIT SYSTEM USER -->
  <div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header bg-PDU text-white">
          <h5 class="modal-title fw-bold">Edit System User</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form onsubmit="saveUserEdit(event)">
          <input type="hidden" id="editUserId">
          <div class="modal-body">
            <div class="alert alert-danger d-none" id="editUserAlert" role="alert"></div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-bold" for="editUserDisplayName">Full Name</label>
                <input type="text" class="form-control" id="editUserDisplayName" maxlength="50" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold" for="editUserPhone">Phone Number</label>
                <input type="tel" class="form-control" id="editUserPhone" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold" for="editUserEmail">Email</label>
                <input type="email" class="form-control" id="editUserEmail">
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold" for="editUserEntity">Entity</label>
                <select class="form-select" id="editUserEntity" required>
                  <option value="Depoint">Depoint</option>
                  <option value="PDU">PDU</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold" for="editUserRole">Role</label>
                <select class="form-select" id="editUserRole" required>
                  <option value="user">User</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold" for="editUserPassword">New Password</label>
                <input type="password" class="form-control" id="editUserPassword" autocomplete="new-password">
                <div class="form-text">Leave blank to keep the current password.</div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary bg-PDU">Save User</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- MODAL 3: VIEW / ADVANCE STAGES -->
  <div class="modal fade" id="stageDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="modalProcTitle">Stage & Timeframe Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="timeline-steps" id="modalTimelineBar">
            <!-- Dynamic Step Icons -->
          </div>
          <hr>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light">
<tr>
                    <th>Timeframe Allowance</th>
                    <th>Responsible Party</th>
                    <th>Target Milestone Date</th>
                    <th>Action Date</th>
                    <th>Stage Status</th>
                    <th class="no-print">Action</th>
                    <th>Comment</th>
                  </tr>
              </thead>
              <tbody id="modalStageTableBody">
                <!-- Dynamic Stage Details -->
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: EDIT PROCUREMENT -->
  <div class="modal fade" id="editProcurementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header bg-PDU text-white">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i> Edit Procurement Record</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form onsubmit="saveEditProcurement(event)">
          <input type="hidden" id="editProcId">
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label fw-bold">Procurement Type</label>
                <select class="form-select" id="editType" required>
                  <option value="Supplies & Non-Consultancy">Supplies & Non-Consultancy</option>
                  <option value="Works">Works</option>
                  <option value="Consultancies">Consultancies</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Procuring Entity</label>
                <input type="text" class="form-control" id="editEntity" required>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Reference No.</label>
                <input type="text" class="form-control" id="editRefNo" required>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Procurement Method</label>
                <select class="form-select" id="editMethod" required></select>
              </div>
              <div class="col-md-8">
                <label class="form-label fw-bold">Subject / Description</label>
                <input type="text" class="form-control" id="editTitle" required>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-bold">Initiation Date</label>
                <input type="date" class="form-control" id="editStartDate" required>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-bold">Initiated By</label>
                <input type="text" class="form-control bg-light" id="editInitiator" readonly>
              </div>
            </div>

            <hr>
            <h6 class="fw-bold text-PDU"><i class="bi bi-sliders me-1"></i> Stage Timeframe & Responsible Party</h6>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-secondary">
                  <tr>
                    <th>Stage Name</th>
                    <th style="width: 140px;">Days Allowance</th>
                    <th style="width: 230px;">Responsible Party</th>
                    <th>Calculated Target Date</th>
                  </tr>
                </thead>
                <tbody id="editStageInputsTableBody"></tbody>
              </table>
            </div>

            <div class="row g-3 mt-2">
              <div class="col-md-6">
                <label class="form-label fw-bold text-primary">BEB Notice Date</label>
                <input type="text" class="form-control bg-light fw-bold text-primary" id="editBebDate" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold text-success">Projected Completion Date</label>
                <input type="text" class="form-control bg-light fw-bold text-success" id="editEndDate" readonly>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary bg-PDU">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- MODAL 4: AUTOMATIC DELAY NOTIFICATIONS -->
  <div class="modal fade" id="notificationModal" tabindex="-1">
    <div class="modal-dialog modal-md">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title fw-bold"><i class="bi bi-bell-fill me-2"></i> System Delay Notifications</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
          <ul class="list-group list-group-flush" id="notificationList">
            <!-- Dynamic Delay Notifications -->
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: PROCUREMENT EDIT HISTORY -->
  <div class="modal fade" id="procurementHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-PDU text-white">
          <h5 class="modal-title fw-bold" id="procurementHistoryTitle">Procurement Edit History</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light">
                <tr>
                  <th style="width: 170px;">When</th>
                  <th style="width: 160px;">Who</th>
                  <th style="width: 150px;">Action</th>
                  <th>Changes</th>
                </tr>
              </thead>
              <tbody id="procurementHistoryBody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: COMMENT REQUIRED WARNING -->
  <div class="modal fade" id="commentRequiredModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h6 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> Comment Required</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body text-center py-4">
          <i class="bi bi-pencil-square text-warning display-5 d-block mb-2"></i>
          <p class="mb-3 fw-semibold">Please enter a comment before advancing to the next stage.</p>
          <button type="button" class="btn btn-danger px-4" data-bs-dismiss="modal">OK</button>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: PROCESS COMPLETED SUCCESS -->
  <div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-sm">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h6 class="modal-title fw-bold"><i class="bi bi-check-circle-fill me-1"></i> Process Completed</h6>
          <button type="button" class="btn-close btn-close-white" disabled></button>
        </div>
        <div class="modal-body text-center py-4">
          <i class="bi bi-envelope-check text-success display-5 d-block mb-2"></i>
          <p class="fw-semibold mb-1">Procurement successfully completed.</p>
          <p class="mb-0 small">A confirmation email has been sent to jssekamatte@PDU.go.ug.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-success w-100" onclick="redirectToDashboard()">Go to Dashboard</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap 5 JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    const API = 'api/';
    let statutoryRulesByType = {};
    let processStages = [];
    let stageByCode = {};
    let publicHolidayDates = [];
    const shownDeadlineReminderKeys = new Set();

    async function loadStatutoryRules() {
      const data = await apiGet('statutory_rules.php');

      // The stage catalogue, order and names now come from the database
      // instead of a hardcoded array, so adding a stage is a data change.
      processStages = data.stages || [];
      stageByCode = {};
      processStages.forEach(s => { stageByCode[s.code] = s; });

      statutoryRulesByType = {};
      (data.rules || []).forEach(r => {
        if (!statutoryRulesByType[r.procurement_type]) statutoryRulesByType[r.procurement_type] = {};
        // Periods arrive in catalogue order, so this is the stage sequence the
        // spreadsheet defines for that type and method combination.
        const periods = {};
        (r.periods || []).forEach(p => { periods[p.stage_code] = p; });
        statutoryRulesByType[r.procurement_type][r.method_code] = {
          name: r.method,
          typeCode: r.procurement_type_code,
          periods: r.periods || [],
          byCode: periods
        };
      });

      publicHolidayDates = data.public_holidays || [];
    }

    // Every stage in the catalogue is listed for every method, in catalogue
    // order. The spreadsheet leaves a cell blank for stages a method does not
    // use (EOI has no issuance, micro procurement has no BEB display, and so
    // on), and those are surfaced as zero rather than hidden, so the officer
    // can see the whole sequence and enter a value if one is actually needed.
    // `period_mode: NOT_DEFINED` marks the rows the statute does not specify.
    function stagesForRule(rule) {
      const byCode = (rule && rule.byCode) || {};
      return processStages
        .slice()
        .sort((a, b) => a.stage_order - b.stage_order)
        .map(stage => byCode[stage.code] || {
          stage_code: stage.code,
          stage_name: stage.name,
          stage_order: stage.stage_order,
          period_mode: 'NOT_DEFINED',
          days: 0
        });
    }

    // A stage is anchored to another stage when it runs alongside it rather
    // than after it. The catalogue says which; the old code assumed index 5.
    function anchorDateFor(rule, stageCode, datesByCode) {
      const stage = stageByCode[stageCode];
      if (!stage || !stage.anchors_from_code) return null;
      return datesByCode[stage.anchors_from_code] || null;
    }

    // The BEB and completion dates belong to whichever stage the catalogue
    // marks with that role, so the lookup runs over the catalogue rather than
    // the rule. A stage the rule leaves undefined can still be given a period
    // by the officer, and its date then has to be found the same way.
    function roleDateFor(rule, role, datesByCode) {
      const stage = processStages.find(s => s.date_role === role);
      if (!stage) return null;
      return datesByCode[stage.code] || null;
    }

    let responsiblePartyOptions = [];
    let systemUsers = [];
    let currentUser = null;
    let procurements = [];

    async function showDashboard() {
      document.getElementById('loginScreen').classList.add('d-none');
      document.getElementById('dashboardScreen').classList.remove('d-none');
      document.getElementById('logoutBtn').classList.remove('d-none');
      document.getElementById('notificationBtn').classList.remove('d-none');
      document.getElementById('userRoleBadge').textContent = currentUser.role === 'admin' ? 'Admin User' : 'User';
      document.getElementById('userRoleBadge').className = 'badge ms-2 ' + (currentUser.role === 'admin' ? 'bg-danger' : 'bg-primary');
      document.getElementById('userInfo').textContent = (currentUser.display_name || currentUser.username) + ' (' + currentUser.phone + ')';
      if (currentUser.role === 'admin') {
        document.getElementById('newProcurementBtn').classList.remove('d-none');
        document.getElementById('manageUsersBtn').classList.remove('d-none');
        document.getElementById('welcomeHeading').textContent = 'PDU Administrative Oversight';
      } else {
        document.getElementById('newProcurementBtn').classList.add('d-none');
        document.getElementById('manageUsersBtn').classList.add('d-none');
        document.getElementById('welcomeHeading').textContent = 'Procurement Execution Dashboard';
      }
      document.getElementById('pStartDate').value = new Date().toISOString().split('T')[0];
      document.getElementById('pInitiator').value = currentUser.display_name || currentUser.username;
      await loadStatutoryRules();
      await loadResponsiblePartyOptions();
      populateTypeDropdown();
      updateMethodDropdown();
      loadPresetDefaults();
      await loadDepointAgents();
      triggerDeadlineReminders();
      loadProcurements().then(() => {
        renderUserTable();
        populateFilterMethods();
        renderDashboard();
        checkNotifications();
      });
    }

    async function restoreSession() {
      const saved = localStorage.getItem('currentUser');
      if (!saved) return;
      try {
        currentUser = JSON.parse(saved);
        const last = parseInt(localStorage.getItem('lastActivity') || '0', 10);
        const inactiveMs = Date.now() - last;
        if ((inactiveMs > IDLE_TIMEOUT) || inactiveMs < 0) {
          throw new Error('expired');
        }
        await loadUsers();
        const fresh = systemUsers.find(u => String(u.phone) == String(currentUser.phone) || u.username == currentUser.username);
        if (fresh) currentUser = Object.assign({}, currentUser, fresh);
        resetIdleTimer();
        showDashboard();
      } catch(e) {
        localStorage.removeItem('currentUser');
        localStorage.removeItem('lastActivity');
        currentUser = null;
      }
    }

    // --- API HELPERS ---
    async function apiGet(endpoint) {
      const res = await fetch(API + endpoint);
      return res.json();
    }

    async function apiPost(endpoint, data) {
      const res = await fetch(API + endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      if (res.status === 401 && endpoint !== 'login.php') expireSession();
      return res.json();
    }

    async function apiPut(endpoint, data) {
      const res = await fetch(API + endpoint, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      if (res.status === 401) expireSession();
      return res.json();
    }

    async function apiDelete(endpoint, data) {
      const res = await fetch(API + endpoint, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data || {})
      });
      if (res.status === 401) expireSession();
      return res.json();
    }

    // --- DATA LOADING ---
    async function loadUsers() {
      systemUsers = await apiGet('users.php');
    }

    async function loadProcurements() {
      procurements = await apiGet('procurements.php');
      procurements.forEach(p => {
        p.currentStageIndex = parseInt(p.current_stage_index);
        p.stages.forEach(s => {
          s.targetDays = parseInt(s.target_days);
          s.responsibleParty = s.responsible_party;
          s.targetDate = s.target_date;
        });
      });
    }

    function triggerDeadlineReminders() {
      apiGet('procurements.php?run_reminders=1').catch(error => {
        console.error('Deadline reminder check failed:', error);
      });
    }

    // --- DATE HELPERS ---
    function isWeekend(d) {
      const day = d.getDay();
      return day === 0 || day === 6;
    }

    // publicHolidayDates is loaded from the public_holidays table with the API
    // payload, as MM-DD strings, so the calendar is not duplicated in the JS.
    function isPublicHoliday(d) {
      const mmdd = String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
      return publicHolidayDates.includes(mmdd);
    }

    function isWorkingDay(d) {
      return !isWeekend(d) && !isPublicHoliday(d);
    }

    function addDays(dateStr, days) {
      let d = new Date(dateStr);
      let remaining = parseInt(days || 0);
      while (remaining > 0) {
        d.setDate(d.getDate() + 1);
        if (isWorkingDay(d)) remaining--;
      }
      return d.toISOString().split('T')[0];
    }

    function calculateDaysDifference(targetDateStr) {
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const targetDate = new Date(targetDateStr);
      targetDate.setHours(0, 0, 0, 0);
      if (today <= targetDate) return 0;
      let count = 0;
      const temp = new Date(targetDate);
      while (temp < today) {
        temp.setDate(temp.getDate() + 1);
        if (isWorkingDay(temp)) count++;
      }
      return count;
    }

    function todayStr() {
      const d = new Date();
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function workingDaysBetween(startStr, endStr) {
      if (!startStr || !endStr) return 0;
      const s = new Date(startStr);
      s.setHours(0, 0, 0, 0);
      const e = new Date(endStr);
      e.setHours(0, 0, 0, 0);
      if (e <= s) return 0;
      let count = 0;
      const t = new Date(s);
      while (t < e) {
        t.setDate(t.getDate() + 1);
        if (t > e) break;
        if (isWorkingDay(t)) count++;
      }
      return count;
    }

    function calculateRecordDelay(p) {
      let total = 0;
      p.stages.forEach(s => {
        const target = s.targetDate;
        if (s.completed_at && s.completed_at > target) {
          total += workingDaysBetween(target, s.completed_at);
        }
      });
      if (!(p.currentStageIndex >= p.stages.length - 1)) {
        total += calculateDaysDifference(p.stages[p.currentStageIndex].targetDate);
      }
      return total;
    }

    // --- DROPDOWNS ---
    function populateTypeDropdown() {
      const typeSelect = document.getElementById('pType');
      const types = Object.keys(statutoryRulesByType);
      typeSelect.innerHTML = '<option value="">Select Type</option>';
      types.forEach(type => {
        const option = document.createElement('option');
        option.value = type;
        option.textContent = type;
        typeSelect.appendChild(option);
      });
    }

    function updateMethodDropdown() {
      const type = document.getElementById('pType').value;
      const methodSelect = document.getElementById('pMethod');
      methodSelect.innerHTML = '<option value="">Select Method</option>';
      const methods = statutoryRulesByType[type] || {};
      for (const key in methods) {
        const opt = document.createElement('option');
        opt.value = key;
        opt.textContent = methods[key].name + ' (' + key + ')';
        methodSelect.appendChild(opt);
      }
    }

    async function loadDepointAgents() {
      const agents = await apiGet('users.php?entity=Depoint');
      const agentSelect = document.getElementById('pAgent');
      agentSelect.innerHTML = '<option value="">Select Agent</option>';
      agents.forEach(agent => {
        const option = document.createElement('option');
        option.value = agent.id;
        option.textContent = (agent.display_name || (agent.username !== agent.phone ? agent.username : 'Name not set')) + ' (' + agent.entity + ')';
        agentSelect.appendChild(option);
      });
    }

    async function loadResponsiblePartyOptions() {
      const users = await apiGet('users.php');
      responsiblePartyOptions = [...new Set(users.map(user => user.entity).filter(Boolean))]
        .sort((first, second) => first.localeCompare(second));
    }

    function createResponsiblePartySelect(className, selectedValue) {
      const select = document.createElement('select');
      select.className = 'form-select form-select-sm ' + className;
      const options = [...responsiblePartyOptions];
      if (selectedValue && !options.includes(selectedValue)) options.push(selectedValue);

      options.forEach(value => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        option.selected = value === selectedValue;
        select.appendChild(option);
      });

      if (!selectedValue && currentUser && options.includes(currentUser.entity)) {
        select.value = currentUser.entity;
      }
      return select;
    }

    function populateFilterMethods() {
      const filterMethod = document.getElementById('filterMethod');
      const allMethods = new Set();
      Object.values(statutoryRulesByType).forEach(g => Object.keys(g).forEach(k => allMethods.add(k)));
      filterMethod.innerHTML = '<option value="ALL">All Methods</option>';
      allMethods.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m;
        opt.textContent = m;
        filterMethod.appendChild(opt);
      });
    }

    function renderStageInputs(presetRule) {
      const tbody = document.getElementById('stageInputsTableBody');
      tbody.innerHTML = '';
      const stages = stagesForRule(presetRule);

      stages.forEach(period => {
        const days = period.period_mode === 'MANDATORY' ? (period.days || 0) : 0;
        const tr = document.createElement('tr');
        tr.dataset.stageCode = period.stage_code;
        tr.dataset.periodMode = period.period_mode || '';
        // A period the spreadsheet marks as having no minimum or as not
        // applicable is shown, but the day count is not editable. A stage the
        // rule does not define at all starts at 0 and stays editable, because
        // entering a value for it is the whole point of showing it.
        const editable = period.period_mode === 'MANDATORY'
          || period.period_mode === 'NOT_DEFINED';
        let hint = '';
        if (period.period_mode === 'NO_MINIMUM') hint = 'No minimum';
        else if (period.period_mode === 'NOT_APPLICABLE') hint = 'Not applicable';
        else if (period.period_mode === 'NOT_DEFINED') hint = 'Not defined for this method';
        tr.innerHTML = `
          <td><strong class="small">${period.stage_name}</strong>${hint ? `<div class="text-muted small">${hint}</div>` : ''}</td>
          <td><input type="number" min="0" class="form-control form-control-sm stage-days-input" value="${days}" ${editable ? '' : 'disabled'} onchange="calculateCustomTimeline()"></td>
          <td></td>
          <td><input type="text" class="form-control form-control-sm bg-light stage-date-output" readonly placeholder="Calculated"></td>
        `;
        tr.cells[2].appendChild(createResponsiblePartySelect(
          'stage-party-input',
          (stageByCode[period.stage_code] || {}).default_responsible_party
        ));
        tbody.appendChild(tr);
      });
      calculateCustomTimeline();
    }

    function loadPresetDefaults() {
      const typeKey = document.getElementById('pType').value;
      const methodKey = document.getElementById('pMethod').value;
      const typeRules = statutoryRulesByType[typeKey] || {};
      const rule = typeRules[methodKey] || null;
      document.getElementById('statutoryRuleLabel').textContent = rule
        ? 'Rule: ' + rule.name
        : 'No statutory rule for this type and method; enter the periods manually.';
      renderStageInputs(rule);
    }

    // Walks the stage rows currently in the table, keyed by stage code rather
    // than by position, so inserting or removing a stage cannot shift the
    // meaning of a row.
    function calculateTimelineFrom(rows, startDate) {
      const datesByCode = {};
      let accumulated = startDate;
      rows.forEach(row => {
        const code = row.stageCode;
        const days = parseInt(row.days) || 0;
        const anchor = anchorDateFor(null, code, datesByCode);
        const base = anchor || accumulated;
        accumulated = addDays(base, days);
        datesByCode[code] = accumulated;
        row.date = accumulated;
      });
      return datesByCode;
    }

    function calculateCustomTimeline() {
      const startDate = document.getElementById('pStartDate').value;
      if (!startDate) return;
      const rows = Array.from(document.querySelectorAll('#stageInputsTableBody tr')).map((tr, idx) => ({
        stageCode: tr.dataset.stageCode,
        days: document.querySelectorAll('.stage-days-input')[idx].value,
        date: null
      }));
      const dates = calculateTimelineFrom(rows, startDate);
      rows.forEach((row, idx) => {
        const output = document.querySelectorAll('.stage-date-output')[idx];
        if (output) output.value = row.date;
      });
      const rule = currentRule();
      const beb = roleDateFor(rule, 'BEB', dates);
      const completion = roleDateFor(rule, 'COMPLETION', dates);
      if (beb) document.getElementById('pBebDatePreview').value = beb;
      if (completion) document.getElementById('pEndDatePreview').value = completion;
    }

    function currentRule() {
      const typeRules = statutoryRulesByType[document.getElementById('pType').value] || {};
      return typeRules[document.getElementById('pMethod').value] || null;
    }

    // --- USER MANAGEMENT (PDU ONLY) ---
    async function createUser(e) {
      e.preventDefault();
      if (currentUser.role !== 'admin') return;
      const phone = document.getElementById('newPhone').value;
      const displayName = document.getElementById('newDisplayName').value.trim();
      const password = document.getElementById('newPassword').value;
      const entity = document.getElementById('newEntity').value;
      const email = document.getElementById('newEmail').value;
      const role = document.getElementById('newRole').value;

      await apiPost('register.php', { username: phone, display_name: displayName, phone, email, entity, password, role });
      await loadUsers();
      renderUserTable();
      e.target.reset();
    }

    function renderUserTable() {
      const tbody = document.getElementById('userListTableBody');
      tbody.innerHTML = '';
      systemUsers.forEach(u => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><strong>${u.display_name || (u.username !== u.phone ? u.username : 'Name not set')}</strong></td>
          <td>${u.phone}</td>
          <td>${u.email || '-'}</td>
          <td>${u.entity}</td>
          <td>${u.role === 'admin' ? '<span class="badge bg-danger">Admin</span>' : '<span class="badge bg-primary">User</span>'}</td>
          <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" onclick="openEditUserModal(${u.id})" aria-label="Edit ${u.display_name || u.username}"><i class="bi bi-pencil-square"></i> Edit</button></td>
        `;
        tbody.appendChild(tr);
      });
    }

    function openEditUserModal(userId) {
      const user = systemUsers.find(item => Number(item.id) === Number(userId));
      if (!user || currentUser.role !== 'admin') return;

      document.getElementById('editUserId').value = user.id;
      document.getElementById('editUserDisplayName').value = user.display_name || (user.username !== user.phone ? user.username : '');
      document.getElementById('editUserPhone').value = user.phone;
      document.getElementById('editUserEmail').value = user.email || '';
      document.getElementById('editUserEntity').value = user.entity;
      document.getElementById('editUserRole').value = user.role;
      document.getElementById('editUserPassword').value = '';
      document.getElementById('editUserAlert').classList.add('d-none');
      bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
    }

    async function saveUserEdit(e) {
      e.preventDefault();
      const alertBox = document.getElementById('editUserAlert');
      alertBox.classList.add('d-none');
      const id = Number(document.getElementById('editUserId').value);

      try {
        const result = await apiPut('users.php', {
          id,
          display_name: document.getElementById('editUserDisplayName').value.trim(),
          phone: document.getElementById('editUserPhone').value.trim(),
          email: document.getElementById('editUserEmail').value.trim(),
          entity: document.getElementById('editUserEntity').value,
          role: document.getElementById('editUserRole').value,
          password: document.getElementById('editUserPassword').value
        });
        if (!result.success) throw new Error(result.error || 'Could not update user account.');

        await loadUsers();
        renderUserTable();
        const updatedUser = systemUsers.find(user => Number(user.id) === id);
        if (updatedUser && Number(currentUser.id) === id) {
          currentUser = Object.assign({}, currentUser, updatedUser);
          localStorage.setItem('currentUser', JSON.stringify(currentUser));
          document.getElementById('userInfo').textContent = (currentUser.display_name || currentUser.username) + ' (' + currentUser.phone + ')';
          document.getElementById('userRoleBadge').textContent = currentUser.role === 'admin' ? 'Admin User' : 'User';
          document.getElementById('userRoleBadge').className = 'badge ms-2 ' + (currentUser.role === 'admin' ? 'bg-danger' : 'bg-primary');
          document.getElementById('newProcurementBtn').classList.toggle('d-none', currentUser.role !== 'admin');
          document.getElementById('manageUsersBtn').classList.toggle('d-none', currentUser.role !== 'admin');
        }
        bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
      } catch (error) {
        alertBox.textContent = error.message || 'Could not update user account. Please try again.';
        alertBox.classList.remove('d-none');
      }
    }

    // --- AUTHENTICATION ---
    async function handleLogin(e) {
      e.preventDefault();
      const emailInput = document.getElementById('loginEmail').value.trim();
      const passInput = document.getElementById('loginPassword').value.trim();
      const alertBox = document.getElementById('loginAlert');

      const result = await apiPost('login.php', { email: emailInput, password: passInput });

      if (!result.success) {
        alertBox.textContent = result.error || 'Invalid Email Address or Password!';
        alertBox.classList.remove('d-none');
        return;
      }

      currentUser = result.user;
      localStorage.setItem('currentUser', JSON.stringify(currentUser));
      alertBox.classList.add('d-none');
      resetIdleTimer();
      await showDashboard();
    }

    function logout() {
      apiPost('login.php', { action: 'logout' }).catch(() => {});
      currentUser = null;
      localStorage.removeItem('currentUser');
      document.getElementById('loginScreen').classList.remove('d-none');
      document.getElementById('dashboardScreen').classList.add('d-none');
      document.getElementById('logoutBtn').classList.add('d-none');
      document.getElementById('notificationBtn').classList.add('d-none');
      document.getElementById('manageUsersBtn').classList.add('d-none');
      document.getElementById('userRoleBadge').textContent = 'Guest';
      document.getElementById('userRoleBadge').className = 'badge bg-warning text-dark ms-2';
      document.getElementById('userInfo').textContent = 'Not Logged In';
    }

    // --- SESSION AUTO-EXPIRE AFTER 30 MINUTES OF INACTIVITY ---
    const IDLE_TIMEOUT = 30 * 60 * 1000;
    const IDLE_CHECK_INTERVAL = 60 * 1000;

    function resetIdleTimer() {
      localStorage.setItem('lastActivity', String(Date.now()));
    }

    function isSessionExpired() {
      const last = parseInt(localStorage.getItem('lastActivity') || '0', 10);
      return currentUser && last > 0 && (Date.now() - last) > IDLE_TIMEOUT;
    }

    function expireSession() {
      const failed = currentUser;
      logout();
      if (failed) {
        const alertBox = document.getElementById('loginAlert');
        alertBox.textContent = 'Session expired after 30 minutes of inactivity. Please login again.';
        alertBox.classList.remove('d-none');
      }
    }

    function checkSessionExpiry() {
      if (isSessionExpired()) expireSession();
    }

    ['click', 'keydown', 'mousemove', 'touchstart', 'scroll', 'input', 'change'].forEach(evt =>
      document.addEventListener(evt, () => { if (currentUser) resetIdleTimer(); }, { passive: true })
    );
    setInterval(checkSessionExpiry, IDLE_CHECK_INTERVAL);
    setInterval(() => {
      if (currentUser) {
        loadProcurements().then(checkNotifications).catch(console.error);
        triggerDeadlineReminders();
      }
    }, 15 * 60 * 1000);

    // --- NOTIFICATIONS ---
    function checkNotifications() {
      const notifList = document.getElementById('notificationList');
      notifList.innerHTML = '';
      let totalNotifications = 0;
      const newDeadlineReminderKeys = [];

      procurements.forEach(p => {
        const isCompleted = p.currentStageIndex >= p.stages.length - 1;
        if (isCompleted) return;
        const currentStage = p.stages[p.currentStageIndex];
        if (!currentStage) return;
        const targetDate = currentStage.targetDate || currentStage.target_date;
        const delayDays = calculateDaysDifference(targetDate);
        if (delayDays > 0) {
          totalNotifications++;
          const li = document.createElement('li');
          li.className = 'list-group-item p-3';
          li.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
              <strong class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Stage Delay Alert</strong>
              <span class="badge bg-danger">${delayDays} Days Delayed</span>
            </div>
            <div class="small mt-1">
              <strong>Ref:</strong> ${p.ref_no} - ${p.title}<br>
              <strong>Delayed Stage:</strong> ${currentStage.stage_name || currentStage.name}<br>
              <strong>Responsible Party:</strong> <span class="badge bg-dark">${currentStage.responsibleParty || currentStage.responsible_party}</span><br>
              <small class="text-muted">Target Date was ${currentStage.targetDate || currentStage.target_date}. Action required immediately.</small>
            </div>
          `;
          notifList.appendChild(li);
        } else if (workingDaysBetween(todayStr(), targetDate) === 2) {
          totalNotifications++;
          const reminderKey = `${p.id}:${currentStage.stage_order || p.currentStageIndex}:${targetDate}`;
          if (!shownDeadlineReminderKeys.has(reminderKey)) {
            shownDeadlineReminderKeys.add(reminderKey);
            newDeadlineReminderKeys.push(reminderKey);
          }
          const li = document.createElement('li');
          li.className = 'list-group-item p-3 border-start border-warning border-4';
          li.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
              <strong class="text-warning-emphasis"><i class="bi bi-clock-history me-1"></i> Deadline Reminder</strong>
              <span class="badge bg-warning text-dark">2 working days</span>
            </div>
            <div class="small mt-1">
              <strong>Ref:</strong> ${p.ref_no} - ${p.title}<br>
              <strong>Due stage:</strong> ${currentStage.stage_name || currentStage.name}<br>
              <strong>Responsible Party:</strong> <span class="badge bg-dark">${currentStage.responsibleParty || currentStage.responsible_party || 'N/A'}</span><br>
              <small class="text-muted">Deadline: ${targetDate}</small>
            </div>
          `;
          notifList.appendChild(li);
        }
      });

      document.getElementById('notifCount').textContent = totalNotifications;
      if (totalNotifications === 0) {
        notifList.innerHTML = '<li class="list-group-item text-center text-muted p-4"><i class="bi bi-check-circle-fill text-success fs-3 d-block mb-1"></i> No upcoming deadline or delay alerts.</li>';
      } else if (newDeadlineReminderKeys.length > 0) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('notificationModal')).show();
      }
    }

    // --- RECORD CREATION ---
    async function createProcurement(e) {
      e.preventDefault();
      const alertBox = document.getElementById('createProcurementAlert');
      alertBox.classList.add('d-none');
      const type = document.getElementById('pType').value;
      const agentId = parseInt(document.getElementById('pAgent').value, 10);
      const refNo = document.getElementById('pRef').value;
      const title = document.getElementById('pTitle').value;
      const method = document.getElementById('pMethod').value;
      const startDate = document.getElementById('pStartDate').value;

      const dayInputs = document.querySelectorAll('.stage-days-input');
      const partyInputs = document.querySelectorAll('.stage-party-input');
      const dateOutputs = document.querySelectorAll('.stage-date-output');
      const stageRows = Array.from(document.querySelectorAll('#stageInputsTableBody tr'));

      // A stage the statute does not define is only saved once the officer has
      // given it a period. Leaving it at the 0 it was seeded with keeps the
      // process description accurate: an EOI procurement still has no issuance
      // step until someone says it needs one.
      const stages = stageRows
        .map((tr, idx) => ({
          stage_code: tr.dataset.stageCode,
          period_mode: tr.dataset.periodMode,
          target_days: parseInt(dayInputs[idx].value) || 0,
          responsible_party: partyInputs[idx].value,
          target_date: dateOutputs[idx].value
        }))
        .filter(s => s.period_mode !== 'NOT_DEFINED' || s.target_days > 0);

      const rule = currentRule();
      const dates = {};
      stages.forEach(s => { dates[s.stage_code] = s.target_date; });
      const bebDate = roleDateFor(rule, 'BEB', dates) || '';
      const completionDate = roleDateFor(rule, 'COMPLETION', dates) || '';

      try {
        const result = await apiPost('procurements.php', {
          ref_no: refNo, type, agent_id: agentId, title, method,
          start_date: startDate,
          beb_date: bebDate,
          completion_date: completionDate,
          created_by: currentUser.id,
          stages
        });
        if (!result.success) throw new Error(result.error || 'Could not save procurement record.');
      } catch (error) {
        alertBox.textContent = error.message || 'Could not save procurement record. Check your connection and try again.';
        alertBox.classList.remove('d-none');
        return;
      }

      await loadProcurements();
      renderDashboard();
      checkNotifications();

      const modalEl = document.getElementById('newProcurementModal');
      bootstrap.Modal.getInstance(modalEl).hide();
      e.target.reset();
      alertBox.classList.add('d-none');
      document.getElementById('pInitiator').value = currentUser.display_name || currentUser.username;
      updateMethodDropdown();
      loadPresetDefaults();
    }

    // --- EDIT PROCUREMENT ---
    function openEditModal(procId) {
      const p = procurements.find(item => item.id == procId);
      if (!p) return;

      document.getElementById('editProcId').value = p.id;
      document.getElementById('editType').value = p.type;
      document.getElementById('editEntity').value = p.entity;
      document.getElementById('editRefNo').value = p.ref_no;
      document.getElementById('editTitle').value = p.title;
      document.getElementById('editStartDate').value = p.start_date;
      document.getElementById('editInitiator').value = p.initiator_name || 'Unknown';

      const methodSelect = document.getElementById('editMethod');
      methodSelect.innerHTML = '';
      const methods = statutoryRulesByType[p.type] || {};
      for (const key in methods) {
        const opt = document.createElement('option');
        opt.value = key;
        opt.textContent = methods[key].name + ' (' + key + ')';
        if (key === p.method) opt.selected = true;
        methodSelect.appendChild(opt);
      }

      const tbody = document.getElementById('editStageInputsTableBody');
      tbody.innerHTML = '';
      p.stages.forEach(stg => {
        const tr = document.createElement('tr');
        tr.dataset.stageCode = stg.stage_code || '';
        tr.innerHTML = `
          <td><strong class="small">${stg.stage_name || stg.name}</strong></td>
          <td><input type="number" min="0" class="form-control form-control-sm edit-stage-days" value="${stg.target_days || stg.targetDays}" onchange="calculateEditTimeline()"></td>
          <td></td>
          <td><input type="text" class="form-control form-control-sm bg-light edit-stage-date" readonly value="${stg.target_date || stg.targetDate}"></td>
        `;
        tr.cells[2].appendChild(createResponsiblePartySelect('edit-stage-party', stg.responsible_party || stg.responsibleParty));
        tbody.appendChild(tr);
      });
      calculateEditTimeline();
      new bootstrap.Modal(document.getElementById('editProcurementModal')).show();
    }

    function calculateEditTimeline() {
      const startDate = document.getElementById('editStartDate').value;
      if (!startDate) return;
      const dayInputs = document.querySelectorAll('.edit-stage-days');
      const rows = Array.from(document.querySelectorAll('#editStageInputsTableBody tr')).map((tr, idx) => ({
        stageCode: tr.dataset.stageCode,
        days: dayInputs[idx].value,
        date: null
      }));
      const dates = calculateTimelineFrom(rows, startDate);
      const dateOutputs = document.querySelectorAll('.edit-stage-date');
      rows.forEach((row, idx) => { if (dateOutputs[idx]) dateOutputs[idx].value = row.date; });

      const p = procurements.find(item => item.id == document.getElementById('editProcId').value);
      const typeRules = statutoryRulesByType[(p && p.type) || ''] || {};
      const rule = typeRules[(p && p.method) || ''] || null;
      const beb = roleDateFor(rule, 'BEB', dates);
      const completion = roleDateFor(rule, 'COMPLETION', dates);
      if (beb) document.getElementById('editBebDate').value = beb;
      if (completion) document.getElementById('editEndDate').value = completion;
    }

    async function saveEditProcurement(e) {
      e.preventDefault();
      const id = document.getElementById('editProcId').value;
      const dayInputs = document.querySelectorAll('.edit-stage-days');
      const partyInputs = document.querySelectorAll('.edit-stage-party');
      const dateOutputs = document.querySelectorAll('.edit-stage-date');
      const stageRows = Array.from(document.querySelectorAll('#editStageInputsTableBody tr'));

      const stages = stageRows.map((tr, idx) => ({
        stage_code: tr.dataset.stageCode,
        target_days: parseInt(dayInputs[idx].value) || 0,
        responsible_party: partyInputs[idx].value,
        target_date: dateOutputs[idx].value
      }));

      const dates = {};
      stages.forEach(s => { dates[s.stage_code] = s.target_date; });
      const p = procurements.find(item => item.id == id);
      const typeRules = statutoryRulesByType[(p && p.type) || ''] || {};
      const rule = typeRules[document.getElementById('editMethod').value] || typeRules[(p && p.method) || ''] || null;

      await apiPut('procurements.php', {
        id: parseInt(id),
        ref_no: document.getElementById('editRefNo').value,
        type: document.getElementById('editType').value,
        entity: document.getElementById('editEntity').value,
        title: document.getElementById('editTitle').value,
        method: document.getElementById('editMethod').value,
        start_date: document.getElementById('editStartDate').value,
        beb_date: roleDateFor(rule, 'BEB', dates) || '',
        completion_date: roleDateFor(rule, 'COMPLETION', dates) || '',
        stages
      });

      await loadProcurements();
      renderDashboard();
      checkNotifications();
      bootstrap.Modal.getInstance(document.getElementById('editProcurementModal')).hide();
    }

    // --- DASHBOARD ---
    function renderDashboard() {
      const tbody = document.getElementById('procurementTableBody');
      tbody.innerHTML = '';

      let total = procurements.length;
      let active = 0, delayed = 0, completed = 0;

      populateReportFilters();

      procurements.forEach(p => {
        const isCompleted = p.currentStageIndex >= p.stages.length - 1;
        const delayDays = calculateRecordDelay(p);
        const isDelayed = delayDays > 0;

        if (isCompleted) completed++;
        else if (isDelayed) delayed++;
        else active++;

        const query = document.getElementById('procurementSearch').value.trim().toLowerCase();
        const matchesSearch = [p.ref_no, p.type, p.method, p.initiator_name]
          .some(value => String(value || '').toLowerCase().includes(query));
        if (!matchesSearch) return;

        let statusBadge = isCompleted
          ? '<span class="badge bg-success">Completed</span>'
          : (isDelayed ? '<span class="badge bg-danger">Delayed</span>' : '<span class="badge bg-warning text-dark">On Track</span>');
        let delayBadge = isDelayed
          ? '<span class="badge bg-danger">+' + delayDays + ' Days</span>'
          : '<span class="badge bg-light text-dark border">0 Days</span>';

        const row = document.createElement('tr');
        const isInitiator = p.created_by == currentUser.id;
        const canManage = Boolean(currentUser);
        const canDelete = isInitiator;
        row.innerHTML = `
          <td class="fw-bold">${p.ref_no}</td>
          <td><span class="badge bg-secondary">${p.type || 'N/A'}</span></td>
          <td><span class="badge bg-light text-dark border">${p.method}</span></td>
          <td>${p.start_date}</td>
          <td class="text-primary fw-semibold">${p.beb_date}</td>
          <td>${delayBadge}</td>
          <td>${statusBadge}</td>
          <td>${p.initiator_name || 'Unknown'}</td>
          <td>${p.assigned_to || 'Unknown'}</td>
          <td class="text-end">
            ${!isCompleted && canManage ? '<button class="btn btn-sm btn-outline-warning me-1" onclick="openEditModal(\'' + p.id + '\')"><i class="bi bi-pencil-square"></i> Edit</button>' : ''}
            <button class="btn btn-sm btn-outline-primary" onclick="openStageModal('${p.id}')">
              <i class="bi bi-eye"></i> View Stages
            </button>
            <button class="btn btn-sm btn-outline-secondary ms-1" onclick="openProcurementHistory('${p.id}')" title="Edit history"><i class="bi bi-clock-history"></i></button>
            ${canDelete && !isCompleted ? '<button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteProcurement(' + p.id + ')"><i class="bi bi-trash"></i></button>' : ''}
          </td>
        `;
        tbody.appendChild(row);
      });

      document.getElementById('kpiTotal').textContent = total;
      document.getElementById('kpiActive').textContent = active;
      document.getElementById('kpiDelayed').textContent = delayed;
      document.getElementById('kpiCompleted').textContent = completed;
    }

    // --- VIEW / ADVANCE STAGE MODAL ---
    function openStageModal(procId) {
      const p = procurements.find(item => item.id == procId);
      if (!p) return;

      document.getElementById('modalProcTitle').textContent = p.ref_no + ': ' + p.title + ' (' + p.entity + ')';

      const timelineBar = document.getElementById('modalTimelineBar');
      timelineBar.innerHTML = '';
      p.stages.forEach((stg, idx) => {
        const isCurrentActive = idx === p.currentStageIndex;
        const isPast = idx < p.currentStageIndex;
        const delayDays = isCurrentActive ? calculateDaysDifference(stg.targetDate) : 0;
        let stateClass = isPast ? 'completed' : (isCurrentActive ? (delayDays > 0 ? 'delayed' : 'active') : '');
        const stepDiv = document.createElement('div');
        stepDiv.className = 'step-item ' + stateClass;
        stepDiv.innerHTML = `
          <div class="step-icon">${idx + 1}</div>
          <div class="step-title">${stg.stage_name || stg.name}</div>
          <div class="step-party">${stg.responsibleParty || stg.responsible_party || 'N/A'}</div>
          <div class="step-date">${stg.targetDate || stg.target_date}</div>
        `;
        timelineBar.appendChild(stepDiv);
      });

      const tableBody = document.getElementById('modalStageTableBody');
      tableBody.innerHTML = '';

// Action Date: the date the user clicked Advance Stage (stored as completed_at),
      // today for the active stage, "—" for pending stages
      p.stages.forEach((stg, idx) => {
        const isCurrentActive = idx === p.currentStageIndex;
        const isPast = idx < p.currentStageIndex;
        const canManage = Boolean(currentUser);

        const actionDate = isPast
          ? (stg.completed_at || stg.target_date || stg.targetDate)
          : (isCurrentActive ? todayStr() : '&mdash;');

        let statusText = '<span class="text-muted">Pending</span>';
        if (isPast || isCurrentActive) {
          const target = stg.target_date || stg.targetDate;
          if (actionDate > target) {
            const d = workingDaysBetween(target, actionDate);
            statusText = '<span class="text-danger fw-bold">Delay (' + d + ' day' + (d === 1 ? '' : 's') + ')</span>';
          } else if (actionDate < target) {
            const d = workingDaysBetween(actionDate, target);
            statusText = '<span class="text-warning fw-bold">Early (' + d + ' day' + (d === 1 ? '' : 's') + ' left)</span>';
          } else {
            statusText = '<span class="text-success fw-bold">On time</span>';
          }
        }

        let actionBtn = (isCurrentActive && idx < p.stages.length - 1 && canManage)
          ? '<button class="btn btn-xs btn-success" onclick="advanceStage(' + p.id + ', this)">Advance Stage</button>' : '';

        // Comment: editable only for the active stage when the initiator (or admin) views it; otherwise read-only
        const commentEditable = (isCurrentActive && canManage);
        const comment = (stg.comment || '');
        const commentCell = commentEditable
          ? '<input type="text" class="form-control form-control-sm stage-comment-input" value="' + comment.replace(/"/g, '&quot;') + '" onblur="saveStageComment(' + p.id + ',' + idx + ', this.value)" placeholder="Comment required before advancing...">'
          : '<span class="text-muted small">' + (comment || '&mdash;') + '</span>';

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${stg.target_days || stg.targetDays} Days</td>
          <td><span class="badge bg-light text-dark border">${stg.responsible_party || stg.responsibleParty || 'N/A'}</span></td>
          <td>${stg.target_date || stg.targetDate}</td>
          <td>${actionDate}</td>
          <td>${statusText}</td>
          <td class="no-print">${actionBtn}</td>
          <td>${commentCell}</td>
        `;
        tableBody.appendChild(tr);
      });

      bootstrap.Modal.getOrCreateInstance(document.getElementById('stageDetailsModal')).show();
    }

    async function advanceStage(procId, button) {
      const p = procurements.find(item => item.id == procId);
      if (p && p.currentStageIndex < p.stages.length - 1) {
        // Read the active stage's comment from the modal and require it before advancing
        const inputs = document.querySelectorAll('.stage-comment-input');
        const activeIdx = p.currentStageIndex;
        const commentInput = inputs.length > 0 ? inputs[0].value.trim() : '';
        const comment = (commentInput || p.stages[activeIdx].comment || '').trim();

        if (!comment) {
          new bootstrap.Modal(document.getElementById('commentRequiredModal')).show();
          return;
        }

        if (button) {
          button.disabled = true;
          button.textContent = 'Saving...';
        }

        try {
          const result = await apiPut('procurements.php', { id: procId, current_stage_index: activeIdx + 1, stage_comment: comment, stage_index: activeIdx });
          if (!result.success) throw new Error(result.error || 'Could not advance the procurement stage.');

          await loadProcurements();
          renderDashboard();
          checkNotifications();

          const updated = procurements.find(item => item.id == procId);
          if (updated && updated.currentStageIndex >= updated.stages.length - 1) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('stageDetailsModal')).hide();
            new bootstrap.Modal(document.getElementById('successModal')).show();
            setTimeout(redirectToDashboard, 2000);
          } else {
            openStageModal(procId);
          }
        } catch (error) {
          window.alert(error.message || 'Could not advance the procurement stage. Please try again.');
        } finally {
          if (button && button.isConnected) {
            button.disabled = false;
            button.textContent = 'Advance Stage';
          }
        }
      }
    }

    function redirectToDashboard() {
      window.location.href = 'index.php';
    }

    function auditValue(field, value, record) {
      if (value === null || value === undefined || value === '') return 'Not set';
      if (field === 'current_stage_index') {
        const stage = record.stages?.find(item => Number(item.stage_order) === Number(value));
        return stage ? stage.stage_name : 'Stage ' + (Number(value) + 1);
      }
      return String(value);
    }

    function appendAuditChange(container, label, beforeValue, afterValue) {
      const line = document.createElement('div');
      const title = document.createElement('strong');
      title.textContent = label + ': ';
      const values = document.createElement('span');
      values.textContent = beforeValue === undefined
        ? afterValue
        : beforeValue + '  →  ' + afterValue;
      line.append(title, values);
      container.appendChild(line);
    }

    function appendAuditRecordSummary(container, record) {
      const fields = [['ref_no', 'Reference'], ['title', 'Description']];
      fields.forEach(([field, label]) => {
        appendAuditChange(container, label, undefined, auditValue(field, record[field], record));
      });
    }

    function renderAuditChanges(entry) {
      const container = document.createElement('div');
      container.className = 'audit-change-list small';
      const details = entry.details || {};
      const before = details.before;
      const after = details.after;

      if (!before && after) {
        appendAuditRecordSummary(container, after);
        return container;
      }
      if (before && !after) {
        appendAuditRecordSummary(container, before);
        return container;
      }

      const fields = [
        ['ref_no', 'Reference'], ['title', 'Description'], ['type', 'Procurement type'],
        ['entity', 'Entity'], ['method', 'Method'], ['start_date', 'Start date'],
        ['current_stage_index', 'Current stage']
      ];
      fields.forEach(([field, label]) => {
        if (before[field] !== after[field]) {
          appendAuditChange(container, label, auditValue(field, before[field], before), auditValue(field, after[field], after));
        }
      });

      const beforeStages = before.stages || [];
      const afterStages = after.stages || [];
      const stageFields = [
        ['target_days', 'Days allowed'], ['responsible_party', 'Responsible party'],
        ['comment', 'Comment'], ['completed_at', 'Completed on']
      ];
      const stageOrders = [...new Set([...beforeStages, ...afterStages].map(stage => Number(stage.stage_order)))].sort((a, b) => a - b);
      stageOrders.forEach(order => {
        const oldStage = beforeStages.find(stage => Number(stage.stage_order) === order);
        const newStage = afterStages.find(stage => Number(stage.stage_order) === order);
        const stageName = newStage?.stage_name || oldStage?.stage_name || 'Stage ' + (order + 1);
        if (!oldStage) {
          appendAuditChange(container, stageName, undefined, 'Stage added');
          return;
        }
        if (!newStage) {
          appendAuditChange(container, stageName, undefined, 'Stage removed');
          return;
        }
        stageFields.forEach(([field, label]) => {
          if (oldStage[field] !== newStage[field]) {
            appendAuditChange(container, stageName + ' · ' + label,
              auditValue(field, oldStage[field]), auditValue(field, newStage[field]));
          }
        });
      });

      if (!container.hasChildNodes()) {
        container.textContent = 'No field changes recorded.';
      }
      return container;
    }

    async function openProcurementHistory(procId) {
      const procurement = procurements.find(item => item.id == procId);
      if (!procurement) return;

      document.getElementById('procurementHistoryTitle').textContent = 'Edit History: ' + procurement.ref_no;
      const tbody = document.getElementById('procurementHistoryBody');
      tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Loading history...</td></tr>';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('procurementHistoryModal')).show();

      const history = await apiGet('procurements.php?history=' + encodeURIComponent(procId));
      tbody.innerHTML = '';
      if (!Array.isArray(history) || history.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No history recorded yet.</td></tr>';
        return;
      }

      history.forEach(entry => {
        const row = document.createElement('tr');
        const timeCell = document.createElement('td');
        timeCell.textContent = new Date(entry.created_at.replace(' ', 'T')).toLocaleString();
        const userCell = document.createElement('td');
        userCell.textContent = entry.username;
        const actionCell = document.createElement('td');
        const actionBadge = document.createElement('span');
        actionBadge.className = 'badge bg-secondary';
        actionBadge.textContent = entry.action.replaceAll('_', ' ');
        actionCell.appendChild(actionBadge);
        const changesCell = document.createElement('td');
        changesCell.appendChild(renderAuditChanges(entry));
        row.append(timeCell, userCell, actionCell, changesCell);
        tbody.appendChild(row);
      });
    }

    async function saveStageComment(procId, stageIdx, value) {
      if (!value || !value.trim()) return;
      await apiPut('procurements.php', { id: procId, current_stage_index: null, save_comment: true, stage_comment: value.trim(), stage_index: stageIdx });
    }

    async function deleteProcurement(procId) {
      const p = procurements.find(item => item.id == procId);
      if (!p) return;
      if (!confirm('Are you sure you want to delete record "' + (p.ref_no || '') + '"? This cannot be undone.')) return;
      await apiDelete('procurements.php', { id: procId });
      await loadProcurements();
      renderDashboard();
      checkNotifications();
    }

    // --- REPORTS ---
    function populateReportFilters() {
      const typeSel = document.getElementById('filterType');
      const types = [...new Set(procurements.map(p => p.type).filter(Boolean))];
      const curType = typeSel.value;
      typeSel.innerHTML = '<option value="ALL">All Types</option>' + types.map(t => '<option value="' + t + '">' + t + '</option>').join('');
      if (types.includes(curType)) typeSel.value = curType;
    }

    function renderReports() {
      const tbody = document.getElementById('reportTableBody');
      tbody.innerHTML = '';
      populateReportFilters();
      const typeFilter = document.getElementById('filterType').value;
      const methodFilter = document.getElementById('filterMethod').value;
      const statusFilter = document.getElementById('filterStatus').value;

      procurements.forEach(p => {
        const isCompleted = p.currentStageIndex >= p.stages.length - 1;
        const delayDays = calculateRecordDelay(p);
        const isDelayed = delayDays > 0;
        let currentStatus = isDelayed ? 'Delayed' : (isCompleted ? 'Completed' : 'On Track');

        if (typeFilter !== 'ALL' && p.type !== typeFilter) return;
        if (methodFilter !== 'ALL' && p.method !== methodFilter) return;
        if (statusFilter !== 'ALL' && currentStatus !== statusFilter) return;

        let flagBadge = isDelayed
          ? '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Timeframe Exceeded (+' + delayDays + ' Days)</span>'
          : '<span class="badge bg-success">Compliant</span>';

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td class="fw-bold">${p.ref_no}</td>
          <td>${p.type || 'N/A'}</td>
          
          <td>${p.method}</td>
          <td>${p.start_date}</td>
          <td>${p.beb_date}</td>
          <td>${p.completion_date}</td>
          <td><strong class="${delayDays > 0 ? 'text-danger' : 'text-success'}">${delayDays} Days</strong></td>
          <td><span class="fw-semibold">${currentStatus}</span></td>
          <td>${flagBadge}</td>
        `;
        tbody.appendChild(tr);
      });
    }

    // --- TAB SWITCHING ---
    function switchTab(tabName) {
      document.getElementById('tab-tracker').classList.toggle('active', tabName === 'tracker');
      document.getElementById('tab-reports').classList.toggle('active', tabName === 'reports');
      document.getElementById('trackerTabContent').classList.toggle('d-none', tabName !== 'tracker');
      document.getElementById('reportsTabContent').classList.toggle('d-none', tabName !== 'reports');
      if (tabName === 'reports') renderReports();
    }

    // --- INIT ---
    restoreSession();
  </script>
</body>
</html>