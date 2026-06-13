<aside id="settingsPanel">
    <div class="d-flex align-items-center justify-content-between px-4 border-bottom border-soft" style="height:60px;">
        <div>
            <h2 class="fw-bold fs-6 mb-0">Dashboard Settings</h2>
            <p class="small text-muted-custom mb-0">Customize TEK-C appearance</p>
        </div><button id="settingsClose" class="icon-btn border-0" type="button"><i data-lucide="x"></i></button>
    </div>
    <div class="p-4 d-grid gap-4 overflow-auto thin-scrollbar">
        <div class="rounded-4 border border-primary-subtle bg-primary-subtle p-3 small text-muted-custom lh-base">
            Sidebar, topbar and sidebar text colors are customizable in light mode only. Dark mode automatically uses
            readable dark colors.</div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Sidebar Color</label><input
                id="sidebarColorPicker" type="color" value="#ffffff"
                class="form-control form-control-color w-100 mt-2 rounded-4" style="height:44px;" /></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Topbar Color</label><input
                id="topbarColorPicker" type="color" value="#ffffff"
                class="form-control form-control-color w-100 mt-2 rounded-4" style="height:44px;" /></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Primary Brand Color</label><input
                id="primaryColorPicker" type="color" value="#0f766e"
                class="form-control form-control-color w-100 mt-2 rounded-4" style="height:44px;" /></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Secondary Brand Color</label><input
                id="secondaryColorPicker" type="color" value="#2563eb"
                class="form-control form-control-color w-100 mt-2 rounded-4" style="height:44px;" /></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Sidebar Text Color</label><select
                id="sidebarTextSelect" class="form-select mt-2 rounded-4 fw-semibold">
                <option value="#334155">Dark Slate</option>
                <option value="#ffffff">White</option>
                <option value="#0f172a">Black</option>
                <option value="#dbeafe">Soft Blue</option>
            </select></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Layout Density</label><select
                id="densitySelect" class="form-select mt-2 rounded-4 fw-semibold">
                <option value="comfortable">Comfortable</option>
                <option value="compact">Compact</option>
            </select></div>
        <div class="rounded-4 border border-soft bg-body-tertiary p-3">
            <p class="fw-bold small mb-1">Preview</p>
            <p class="small text-muted-custom mb-0">Changes are saved in your browser automatically.</p><button
                id="resetCustomization" class="btn btn-outline-secondary w-100 mt-3 rounded-4 fw-bold"
                type="button">Reset
                Defaults</button>
        </div>
    </div>
</aside>