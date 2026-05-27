/**
 * FormFlow Visual Form Builder
 * Drag-and-drop form builder with conditional logic support
 */

(function($) {
    'use strict';

    // FormBuilder namespace
    window.ISFFormBuilder = window.ISFFormBuilder || {};

    /**
     * Main Form Builder Class
     */
    class FormBuilder {
        constructor(options = {}) {
            this.container = $(options.container || '#isf-form-builder');
            this.instanceId = options.instanceId || 0;
            this.schema = options.schema || this.getDefaultSchema();
            this.fieldTypes = options.fieldTypes || {};
            this.currentStep = 0;
            this.selectedField = null;
            this.isDirty = false;
            this.history = [];
            this.historyIndex = -1;
            this.maxHistory = 50;

            // Bind methods
            this.init = this.init.bind(this);
            this.render = this.render.bind(this);
            this.save = this.save.bind(this);

            // Initialize if container exists
            if (this.container.length) {
                this.init();
            }
        }

        /**
         * Get default empty schema
         */
        getDefaultSchema() {
            return {
                version: '1.0',
                settings: {
                    title: 'New Form',
                    description: '',
                    submit_text: 'Submit',
                    success_message: 'Thank you for your submission.',
                    show_progress: true,
                    enable_autosave: false,
                    conditional_logic: true
                },
                steps: [{
                    id: 'step_1',
                    title: 'Step 1',
                    description: '',
                    fields: []
                }],
                conditions: []
            };
        }

        /**
         * Initialize the form builder
         */
        init() {
            this.buildUI();
            this.bindEvents();
            this.initDragDrop();
            this.render();
            this.saveHistory();

            // Mark as initialized
            this.container.addClass('isf-builder-initialized');
        }

        /**
         * Build the main UI structure
         */
        buildUI() {
            const html = `
                <div class="isf-builder-wrapper">
                    <!-- Toolbar -->
                    <div class="isf-builder-toolbar">
                        <div class="isf-toolbar-left">
                            <button type="button" class="isf-btn isf-btn-icon isf-btn-undo" title="Undo (Ctrl+Z)" disabled>
                                <span class="dashicons dashicons-undo"></span>
                            </button>
                            <button type="button" class="isf-btn isf-btn-icon isf-btn-redo" title="Redo (Ctrl+Y)" disabled>
                                <span class="dashicons dashicons-redo"></span>
                            </button>
                            <span class="isf-toolbar-divider"></span>
                            <button type="button" class="isf-btn isf-btn-icon isf-btn-preview" title="Preview Form">
                                <span class="dashicons dashicons-visibility"></span>
                                <span class="isf-btn-label">Preview</span>
                            </button>
                        </div>
                        <div class="isf-toolbar-center">
                            <input type="text" class="isf-form-title" value="${this.escapeHtml(this.schema.settings.title)}" placeholder="Form Title">
                        </div>
                        <div class="isf-toolbar-right">
                            <span class="isf-save-status"></span>
                            <button type="button" class="isf-btn isf-btn-primary isf-btn-save">
                                <span class="dashicons dashicons-saved"></span>
                                <span class="isf-btn-label">Save Form</span>
                            </button>
                        </div>
                    </div>

                    <!-- Main Content Area -->
                    <div class="isf-builder-main">
                        <!-- Field Palette (Left Sidebar) -->
                        <div class="isf-builder-palette">
                            <div class="isf-palette-search">
                                <input type="text" placeholder="Search fields..." class="isf-field-search">
                                <span class="dashicons dashicons-search"></span>
                            </div>
                            <div class="isf-palette-fields"></div>
                        </div>

                        <!-- Canvas (Center) -->
                        <div class="isf-builder-canvas">
                            <div class="isf-canvas-header">
                                <div class="isf-steps-nav"></div>
                                <button type="button" class="isf-btn isf-btn-sm isf-btn-add-step">
                                    <span class="dashicons dashicons-plus-alt2"></span>
                                    Add Step
                                </button>
                            </div>
                            <div class="isf-canvas-body">
                                <div class="isf-step-content"></div>
                            </div>
                        </div>

                        <!-- Properties Panel (Right Sidebar) -->
                        <div class="isf-builder-properties">
                            <div class="isf-properties-header">
                                <h3>Properties</h3>
                                <button type="button" class="isf-btn isf-btn-icon isf-btn-close-props" title="Close">
                                    <span class="dashicons dashicons-no-alt"></span>
                                </button>
                            </div>
                            <div class="isf-properties-content">
                                <div class="isf-no-selection">
                                    <span class="dashicons dashicons-forms"></span>
                                    <p>Select a field to edit its properties</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Conditional Logic Modal -->
                    <div class="isf-modal isf-modal-conditions" style="display:none;">
                        <div class="isf-modal-overlay"></div>
                        <div class="isf-modal-content">
                            <div class="isf-modal-header">
                                <h3>Conditional Logic</h3>
                                <button type="button" class="isf-modal-close">&times;</button>
                            </div>
                            <div class="isf-modal-body"></div>
                            <div class="isf-modal-footer">
                                <button type="button" class="isf-btn isf-btn-secondary isf-modal-cancel">Cancel</button>
                                <button type="button" class="isf-btn isf-btn-primary isf-modal-save">Save Conditions</button>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Modal -->
                    <div class="isf-modal isf-modal-preview" style="display:none;">
                        <div class="isf-modal-overlay"></div>
                        <div class="isf-modal-content isf-modal-lg">
                            <div class="isf-modal-header">
                                <h3>Form Preview</h3>
                                <div class="isf-preview-device-toggle">
                                    <button type="button" class="isf-btn isf-btn-icon active" data-device="desktop" title="Desktop">
                                        <span class="dashicons dashicons-desktop"></span>
                                    </button>
                                    <button type="button" class="isf-btn isf-btn-icon" data-device="tablet" title="Tablet">
                                        <span class="dashicons dashicons-tablet"></span>
                                    </button>
                                    <button type="button" class="isf-btn isf-btn-icon" data-device="mobile" title="Mobile">
                                        <span class="dashicons dashicons-smartphone"></span>
                                    </button>
                                </div>
                                <button type="button" class="isf-modal-close">&times;</button>
                            </div>
                            <div class="isf-modal-body">
                                <div class="isf-preview-frame"></div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            this.container.html(html);
            this.buildFieldPalette();
        }

        /**
         * Build the field palette with categorized field types
         */
        buildFieldPalette() {
            const $palette = this.container.find('.isf-palette-fields');
            const categories = this.getFieldCategories();

            let html = '';

            for (const [catKey, category] of Object.entries(categories)) {
                html += `
                    <div class="isf-palette-category" data-category="${catKey}">
                        <div class="isf-category-header">
                            <span class="dashicons ${category.icon}"></span>
                            <span class="isf-category-title">${this.escapeHtml(category.label)}</span>
                            <span class="dashicons dashicons-arrow-down-alt2 isf-category-toggle"></span>
                        </div>
                        <div class="isf-category-fields">
                `;

                for (const field of category.fields) {
                    html += `
                        <div class="isf-palette-field" data-type="${field.type}" draggable="true">
                            <span class="dashicons ${field.icon}"></span>
                            <span class="isf-field-label">${this.escapeHtml(field.label)}</span>
                        </div>
                    `;
                }

                html += `
                        </div>
                    </div>
                `;
            }

            $palette.html(html);
        }

        /**
         * Get field categories with their field types
         */
        getFieldCategories() {
            return {
                basic: {
                    label: 'Basic Fields',
                    icon: 'dashicons-edit',
                    fields: [
                        { type: 'text', label: 'Text', icon: 'dashicons-editor-textcolor' },
                        { type: 'textarea', label: 'Textarea', icon: 'dashicons-text' },
                        { type: 'email', label: 'Email', icon: 'dashicons-email' },
                        { type: 'phone', label: 'Phone', icon: 'dashicons-phone' },
                        { type: 'number', label: 'Number', icon: 'dashicons-calculator' },
                        { type: 'password', label: 'Password', icon: 'dashicons-lock' },
                        { type: 'hidden', label: 'Hidden', icon: 'dashicons-hidden' }
                    ]
                },
                selection: {
                    label: 'Selection Fields',
                    icon: 'dashicons-list-view',
                    fields: [
                        { type: 'select', label: 'Dropdown', icon: 'dashicons-arrow-down-alt' },
                        { type: 'radio', label: 'Radio Buttons', icon: 'dashicons-marker' },
                        { type: 'checkbox', label: 'Checkboxes', icon: 'dashicons-yes-alt' },
                        { type: 'checkbox_single', label: 'Single Checkbox', icon: 'dashicons-yes' }
                    ]
                },
                advanced: {
                    label: 'Advanced Fields',
                    icon: 'dashicons-admin-tools',
                    fields: [
                        { type: 'date', label: 'Date Picker', icon: 'dashicons-calendar-alt' },
                        { type: 'time', label: 'Time Picker', icon: 'dashicons-clock' },
                        { type: 'datetime', label: 'Date & Time', icon: 'dashicons-calendar' },
                        { type: 'file', label: 'File Upload', icon: 'dashicons-upload' },
                        { type: 'signature', label: 'Signature', icon: 'dashicons-art' },
                        { type: 'rating', label: 'Rating', icon: 'dashicons-star-filled' },
                        { type: 'slider', label: 'Slider', icon: 'dashicons-leftright' }
                    ]
                },
                address: {
                    label: 'Address Fields',
                    icon: 'dashicons-location',
                    fields: [
                        { type: 'address', label: 'Full Address', icon: 'dashicons-location-alt' },
                        { type: 'street', label: 'Street', icon: 'dashicons-admin-home' },
                        { type: 'city', label: 'City', icon: 'dashicons-building' },
                        { type: 'state', label: 'State', icon: 'dashicons-flag' },
                        { type: 'zip', label: 'ZIP Code', icon: 'dashicons-location' },
                        { type: 'country', label: 'Country', icon: 'dashicons-admin-site' }
                    ]
                },
                utility: {
                    label: 'Utility Fields',
                    icon: 'dashicons-lightbulb',
                    fields: [
                        { type: 'account_number', label: 'Account Number', icon: 'dashicons-id-alt' },
                        { type: 'meter_number', label: 'Meter Number', icon: 'dashicons-dashboard' },
                        { type: 'program_selector', label: 'Program Selector', icon: 'dashicons-networking' },
                        { type: 'service_address', label: 'Service Address', icon: 'dashicons-location' },
                        { type: 'appointment_picker', label: 'Appointment Picker', icon: 'dashicons-calendar' }
                    ]
                },
                layout: {
                    label: 'Layout Elements',
                    icon: 'dashicons-layout',
                    fields: [
                        { type: 'heading', label: 'Heading', icon: 'dashicons-heading' },
                        { type: 'paragraph', label: 'Paragraph', icon: 'dashicons-editor-paragraph' },
                        { type: 'divider', label: 'Divider', icon: 'dashicons-minus' },
                        { type: 'columns', label: 'Columns', icon: 'dashicons-columns' },
                        { type: 'section', label: 'Section', icon: 'dashicons-align-wide' },
                        { type: 'html', label: 'HTML Block', icon: 'dashicons-html' }
                    ]
                }
            };
        }

        /**
         * Bind event handlers
         */
        bindEvents() {
            const self = this;

            // Toolbar events
            this.container.on('click', '.isf-btn-save', () => this.save());
            this.container.on('click', '.isf-btn-undo', () => this.undo());
            this.container.on('click', '.isf-btn-redo', () => this.redo());
            this.container.on('click', '.isf-btn-preview', () => this.showPreview());
            this.container.on('input', '.isf-form-title', (e) => {
                this.schema.settings.title = $(e.target).val();
                this.markDirty();
            });

            // Step navigation
            this.container.on('click', '.isf-step-tab', (e) => {
                const index = $(e.currentTarget).data('step-index');
                this.switchStep(index);
            });
            this.container.on('click', '.isf-btn-add-step', () => this.addStep());
            this.container.on('click', '.isf-step-delete', (e) => {
                e.stopPropagation();
                const index = $(e.currentTarget).closest('.isf-step-tab').data('step-index');
                this.deleteStep(index);
            });

            // Field palette
            this.container.on('click', '.isf-category-header', (e) => {
                $(e.currentTarget).closest('.isf-palette-category').toggleClass('collapsed');
            });
            this.container.on('input', '.isf-field-search', (e) => {
                this.filterFields($(e.target).val());
            });

            // Canvas field events
            this.container.on('click', '.isf-canvas-field', (e) => {
                e.stopPropagation();
                this.selectField($(e.currentTarget).data('field-id'));
            });
            this.container.on('click', '.isf-field-delete', (e) => {
                e.stopPropagation();
                const fieldId = $(e.currentTarget).closest('.isf-canvas-field').data('field-id');
                this.deleteField(fieldId);
            });
            this.container.on('click', '.isf-field-duplicate', (e) => {
                e.stopPropagation();
                const fieldId = $(e.currentTarget).closest('.isf-canvas-field').data('field-id');
                this.duplicateField(fieldId);
            });
            this.container.on('click', '.isf-field-conditions', (e) => {
                e.stopPropagation();
                const fieldId = $(e.currentTarget).closest('.isf-canvas-field').data('field-id');
                this.openConditionsModal(fieldId);
            });

            // Deselect field when clicking canvas background
            this.container.on('click', '.isf-canvas-body', (e) => {
                if ($(e.target).hasClass('isf-canvas-body') || $(e.target).hasClass('isf-step-content')) {
                    this.deselectField();
                }
            });

            // Properties panel events
            this.container.on('click', '.isf-btn-close-props', () => this.deselectField());
            this.container.on('input change', '.isf-properties-content input, .isf-properties-content select, .isf-properties-content textarea', (e) => {
                this.updateFieldProperty(e);
            });

            // Modal events
            this.container.on('click', '.isf-modal-overlay, .isf-modal-close, .isf-modal-cancel', () => {
                this.closeModals();
            });
            this.container.on('click', '.isf-modal-save', (e) => {
                const $modal = $(e.target).closest('.isf-modal');
                if ($modal.hasClass('isf-modal-conditions')) {
                    this.saveConditions();
                }
            });

            // Preview device toggle
            this.container.on('click', '.isf-preview-device-toggle .isf-btn', (e) => {
                const device = $(e.currentTarget).data('device');
                this.container.find('.isf-preview-device-toggle .isf-btn').removeClass('active');
                $(e.currentTarget).addClass('active');
                this.container.find('.isf-preview-frame')
                    .removeClass('device-desktop device-tablet device-mobile')
                    .addClass('device-' + device);
            });

            // Keyboard shortcuts
            $(document).on('keydown', (e) => {
                if (!this.container.is(':visible')) return;

                // Ctrl+S / Cmd+S - Save
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    this.save();
                }
                // Ctrl+Z / Cmd+Z - Undo
                if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key === 'z') {
                    e.preventDefault();
                    this.undo();
                }
                // Ctrl+Shift+Z / Cmd+Shift+Z or Ctrl+Y - Redo
                if (((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'z') ||
                    ((e.ctrlKey || e.metaKey) && e.key === 'y')) {
                    e.preventDefault();
                    this.redo();
                }
                // Delete - Delete selected field
                if (e.key === 'Delete' && this.selectedField) {
                    e.preventDefault();
                    this.deleteField(this.selectedField);
                }
                // Escape - Deselect/close modals
                if (e.key === 'Escape') {
                    this.closeModals();
                    this.deselectField();
                }
            });

            // Warn before leaving with unsaved changes
            $(window).on('beforeunload', (e) => {
                if (this.isDirty) {
                    e.preventDefault();
                    return 'You have unsaved changes. Are you sure you want to leave?';
                }
            });
        }

        /**
         * Initialize drag and drop functionality (HTML5 API with touch support)
         */
        initDragDrop() {
            const self = this;

            // Store dragged element data
            this.dragData = {
                element: null,
                type: null,
                fieldId: null,
                startX: 0,
                startY: 0,
                isDragging: false
            };

            // Make palette fields draggable
            this.container.find('.isf-palette-field').each(function() {
                const $field = $(this);

                // Mouse/Touch drag start
                $field.on('dragstart', function(e) {
                    const type = $field.data('type');
                    e.originalEvent.dataTransfer.setData('field-type', type);
                    e.originalEvent.dataTransfer.effectAllowed = 'copy';

                    // Add visual feedback
                    $field.addClass('isf-dragging');
                    self.dragData.type = type;
                    self.dragData.element = this;

                    // Create ghost image
                    const ghost = $field.clone().addClass('isf-drag-ghost')[0];
                    document.body.appendChild(ghost);
                    e.originalEvent.dataTransfer.setDragImage(ghost, 20, 20);
                    setTimeout(() => ghost.remove(), 0);
                });

                $field.on('dragend', function() {
                    $field.removeClass('isf-dragging');
                    self.container.find('.isf-drop-indicator, .isf-drop-zone-active').remove();
                    self.container.find('.isf-drop-zone-highlight').removeClass('isf-drop-zone-highlight');
                    self.dragData = { element: null, type: null, fieldId: null, startX: 0, startY: 0, isDragging: false };
                });

                // Touch support
                $field.on('touchstart', function(e) {
                    const touch = e.originalEvent.touches[0];
                    self.dragData.type = $field.data('type');
                    self.dragData.element = this;
                    self.dragData.startX = touch.clientX;
                    self.dragData.startY = touch.clientY;
                    self.dragData.isDragging = true;

                    $field.addClass('isf-dragging');
                    e.preventDefault();
                });
            });

            // Touch move handler for palette
            this.container.on('touchmove', '.isf-palette-field', function(e) {
                if (!self.dragData.isDragging) return;

                const touch = e.originalEvent.touches[0];
                const dx = touch.clientX - self.dragData.startX;
                const dy = touch.clientY - self.dragData.startY;

                // Show visual feedback if dragged enough
                if (Math.abs(dx) > 5 || Math.abs(dy) > 5) {
                    self.handleTouchDrag(touch);
                }
                e.preventDefault();
            });

            this.container.on('touchend', '.isf-palette-field', function(e) {
                if (!self.dragData.isDragging) return;

                const touch = e.originalEvent.changedTouches[0];
                self.handleTouchDrop(touch);

                $(this).removeClass('isf-dragging');
                self.container.find('.isf-drop-indicator, .isf-drop-zone-highlight').remove();
                self.dragData.isDragging = false;
                e.preventDefault();
            });

            // Make canvas a drop zone with highlighting
            this.container.find('.isf-step-content').on('dragenter', function(e) {
                e.preventDefault();
                $(this).addClass('isf-drop-zone-highlight');
            });

            this.container.find('.isf-step-content').on('dragover', function(e) {
                e.preventDefault();
                e.originalEvent.dataTransfer.dropEffect = 'copy';

                // Show drop indicator with smooth animation
                const $fields = $(this).find('.isf-canvas-field');
                const mouseY = e.originalEvent.clientY;
                let insertBefore = null;

                $fields.each(function() {
                    const rect = this.getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    if (mouseY < midY && !insertBefore) {
                        insertBefore = this;
                    }
                });

                self.container.find('.isf-drop-indicator').remove();
                const $indicator = $('<div class="isf-drop-indicator"><div class="isf-drop-indicator-line"></div></div>');

                if (insertBefore) {
                    $(insertBefore).before($indicator);
                } else {
                    $(this).append($indicator);
                }

                // Animate indicator
                setTimeout(() => $indicator.addClass('isf-visible'), 10);
            });

            this.container.find('.isf-step-content').on('dragleave', function(e) {
                if (!$(e.relatedTarget).closest('.isf-step-content').length) {
                    self.container.find('.isf-drop-indicator').remove();
                    $(this).removeClass('isf-drop-zone-highlight');
                }
            });

            this.container.find('.isf-step-content').on('drop', function(e) {
                e.preventDefault();
                self.container.find('.isf-drop-indicator').remove();
                $(this).removeClass('isf-drop-zone-highlight');

                const type = e.originalEvent.dataTransfer.getData('field-type');
                if (!type) return;

                // Find insert position
                const $fields = $(this).find('.isf-canvas-field');
                const mouseY = e.originalEvent.clientY;
                let insertIndex = $fields.length;

                $fields.each(function(index) {
                    const rect = this.getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    if (mouseY < midY && insertIndex === $fields.length) {
                        insertIndex = index;
                    }
                });

                self.addField(type, insertIndex);
            });

            // Initialize sortable for canvas fields (reordering)
            this.initFieldSorting();
        }

        /**
         * Initialize field sorting with HTML5 drag and drop
         */
        initFieldSorting() {
            const self = this;
            let draggedField = null;

            this.container.on('dragstart', '.isf-field-drag-handle', function(e) {
                const $field = $(this).closest('.isf-canvas-field');
                draggedField = $field[0];

                $field.addClass('isf-field-dragging');
                e.originalEvent.dataTransfer.effectAllowed = 'move';
                e.originalEvent.dataTransfer.setData('field-id', $field.data('field-id'));

                // Create visual drag handle
                const ghost = $field.clone().addClass('isf-drag-ghost')[0];
                document.body.appendChild(ghost);
                e.originalEvent.dataTransfer.setDragImage(ghost, 20, 20);
                setTimeout(() => ghost.remove(), 0);
            });

            this.container.on('dragover', '.isf-canvas-field', function(e) {
                if (!draggedField) return;
                e.preventDefault();

                const $field = $(this);
                if ($field[0] === draggedField) return;

                const rect = this.getBoundingClientRect();
                const midY = rect.top + rect.height / 2;
                const isAbove = e.originalEvent.clientY < midY;

                $field.removeClass('isf-drag-over-top isf-drag-over-bottom');
                $field.addClass(isAbove ? 'isf-drag-over-top' : 'isf-drag-over-bottom');
            });

            this.container.on('dragleave', '.isf-canvas-field', function(e) {
                if (!$(e.relatedTarget).closest('.isf-canvas-field').length) {
                    $(this).removeClass('isf-drag-over-top isf-drag-over-bottom');
                }
            });

            this.container.on('drop', '.isf-canvas-field', function(e) {
                if (!draggedField) return;
                e.preventDefault();

                const $target = $(this);
                const $dragged = $(draggedField);

                if ($target[0] === draggedField) return;

                const rect = this.getBoundingClientRect();
                const midY = rect.top + rect.height / 2;
                const isAbove = e.originalEvent.clientY < midY;

                if (isAbove) {
                    $target.before($dragged);
                } else {
                    $target.after($dragged);
                }

                $target.removeClass('isf-drag-over-top isf-drag-over-bottom');
                self.reorderFields();
            });

            this.container.on('dragend', '.isf-canvas-field', function(e) {
                $(this).removeClass('isf-field-dragging isf-drag-over-top isf-drag-over-bottom');
                draggedField = null;
            });

            // Touch support for field reordering
            this.initFieldTouchSorting();
        }

        /**
         * Handle touch dragging for palette fields
         */
        handleTouchDrag(touch) {
            const elementAtPoint = document.elementFromPoint(touch.clientX, touch.clientY);
            const $dropZone = $(elementAtPoint).closest('.isf-step-content');

            if ($dropZone.length) {
                $dropZone.addClass('isf-drop-zone-highlight');

                // Show drop indicator
                const $fields = $dropZone.find('.isf-canvas-field');
                let insertBefore = null;

                $fields.each(function() {
                    const rect = this.getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    if (touch.clientY < midY && !insertBefore) {
                        insertBefore = this;
                    }
                });

                this.container.find('.isf-drop-indicator').remove();
                const $indicator = $('<div class="isf-drop-indicator"><div class="isf-drop-indicator-line"></div></div>');

                if (insertBefore) {
                    $(insertBefore).before($indicator);
                } else {
                    $dropZone.append($indicator);
                }

                setTimeout(() => $indicator.addClass('isf-visible'), 10);
            }
        }

        /**
         * Handle touch drop for palette fields
         */
        handleTouchDrop(touch) {
            const elementAtPoint = document.elementFromPoint(touch.clientX, touch.clientY);
            const $dropZone = $(elementAtPoint).closest('.isf-step-content');

            if ($dropZone.length && this.dragData.type) {
                const $fields = $dropZone.find('.isf-canvas-field');
                let insertIndex = $fields.length;

                $fields.each(function(index) {
                    const rect = this.getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    if (touch.clientY < midY && insertIndex === $fields.length) {
                        insertIndex = index;
                    }
                });

                this.addField(this.dragData.type, insertIndex);
            }
        }

        /**
         * Initialize touch-based sorting for canvas fields
         */
        initFieldTouchSorting() {
            const self = this;
            let touchDragData = { field: null, startY: 0, currentY: 0 };

            this.container.on('touchstart', '.isf-field-drag-handle', function(e) {
                const $field = $(this).closest('.isf-canvas-field');
                const touch = e.originalEvent.touches[0];

                touchDragData.field = $field[0];
                touchDragData.startY = touch.clientY;
                touchDragData.currentY = touch.clientY;

                $field.addClass('isf-field-touch-dragging');
                e.preventDefault();
            });

            this.container.on('touchmove', function(e) {
                if (!touchDragData.field) return;

                const touch = e.originalEvent.touches[0];
                touchDragData.currentY = touch.clientY;

                // Visual feedback
                const $dragged = $(touchDragData.field);
                const translateY = touch.clientY - touchDragData.startY;
                $dragged.css('transform', `translateY(${translateY}px)`);

                // Find target position
                const elementAtPoint = document.elementFromPoint(touch.clientX, touch.clientY);
                const $target = $(elementAtPoint).closest('.isf-canvas-field');

                if ($target.length && $target[0] !== touchDragData.field) {
                    const rect = $target[0].getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;

                    self.container.find('.isf-canvas-field').removeClass('isf-drag-over-top isf-drag-over-bottom');
                    $target.addClass(touch.clientY < midY ? 'isf-drag-over-top' : 'isf-drag-over-bottom');
                }

                e.preventDefault();
            });

            this.container.on('touchend', function(e) {
                if (!touchDragData.field) return;

                const touch = e.originalEvent.changedTouches[0];
                const elementAtPoint = document.elementFromPoint(touch.clientX, touch.clientY);
                const $target = $(elementAtPoint).closest('.isf-canvas-field');
                const $dragged = $(touchDragData.field);

                if ($target.length && $target[0] !== touchDragData.field) {
                    const rect = $target[0].getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;

                    if (touch.clientY < midY) {
                        $target.before($dragged);
                    } else {
                        $target.after($dragged);
                    }

                    self.reorderFields();
                }

                $dragged.removeClass('isf-field-touch-dragging');
                $dragged.css('transform', '');
                self.container.find('.isf-canvas-field').removeClass('isf-drag-over-top isf-drag-over-bottom');

                touchDragData = { field: null, startY: 0, currentY: 0 };
                e.preventDefault();
            });
        }

        /**
         * Render the form builder
         */
        render() {
            this.renderStepNav();
            this.renderStepContent();
            this.updateUndoRedoButtons();
        }

        /**
         * Render step navigation tabs
         */
        renderStepNav() {
            const $nav = this.container.find('.isf-steps-nav');
            let html = '';

            this.schema.steps.forEach((step, index) => {
                const isActive = index === this.currentStep;
                html += `
                    <div class="isf-step-tab ${isActive ? 'active' : ''}" data-step-index="${index}">
                        <span class="isf-step-number">${index + 1}</span>
                        <span class="isf-step-title">${this.escapeHtml(step.title || 'Step ' + (index + 1))}</span>
                        ${this.schema.steps.length > 1 ? '<button type="button" class="isf-step-delete" title="Delete Step">&times;</button>' : ''}
                    </div>
                `;
            });

            $nav.html(html);
        }

        /**
         * Render the current step's fields
         */
        renderStepContent() {
            const $content = this.container.find('.isf-step-content');
            const step = this.schema.steps[this.currentStep];

            if (!step) {
                $content.html('<div class="isf-empty-step">No step selected</div>');
                return;
            }

            if (!step.fields || step.fields.length === 0) {
                $content.html(`
                    <div class="isf-empty-step">
                        <span class="dashicons dashicons-welcome-add-page"></span>
                        <p>Drag fields here to build your form</p>
                    </div>
                `);
                return;
            }

            let html = '';
            step.fields.forEach((field) => {
                html += this.renderCanvasField(field);
            });

            $content.html(html);

            // Reinitialize sortable
            $content.sortable('refresh');

            // Restore selection if applicable
            if (this.selectedField) {
                this.container.find(`.isf-canvas-field[data-field-id="${this.selectedField}"]`).addClass('selected');
            }
        }

        /**
         * Render a single field on the canvas
         */
        renderCanvasField(field) {
            const fieldConfig = this.getFieldConfig(field.type);
            const hasConditions = this.fieldHasConditions(field.id);
            const isSelected = field.id === this.selectedField;

            return `
                <div class="isf-canvas-field ${isSelected ? 'selected' : ''} ${hasConditions ? 'has-conditions' : ''}"
                     data-field-id="${field.id}"
                     data-field-type="${field.type}">
                    <div class="isf-field-header">
                        <span class="isf-field-drag-handle dashicons dashicons-move"></span>
                        <span class="isf-field-type-icon dashicons ${fieldConfig.icon}"></span>
                        <span class="isf-field-type-label">${this.escapeHtml(fieldConfig.label)}</span>
                        <div class="isf-field-actions">
                            <button type="button" class="isf-field-conditions" title="Conditional Logic">
                                <span class="dashicons dashicons-randomize"></span>
                            </button>
                            <button type="button" class="isf-field-duplicate" title="Duplicate">
                                <span class="dashicons dashicons-admin-page"></span>
                            </button>
                            <button type="button" class="isf-field-delete" title="Delete">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </div>
                    <div class="isf-field-preview">
                        ${this.renderFieldPreview(field)}
                    </div>
                    ${hasConditions ? '<div class="isf-field-conditions-indicator"><span class="dashicons dashicons-randomize"></span> Has conditions</div>' : ''}
                </div>
            `;
        }

        /**
         * Render a field preview for the canvas
         */
        renderFieldPreview(field) {
            const label = field.label || 'Untitled Field';
            const required = field.required ? '<span class="isf-required">*</span>' : '';

            let preview = `<label class="isf-preview-label">${this.escapeHtml(label)}${required}</label>`;

            switch (field.type) {
                case 'text':
                case 'email':
                case 'phone':
                case 'number':
                case 'password':
                case 'account_number':
                case 'meter_number':
                case 'zip':
                    preview += `<input type="text" class="isf-preview-input" placeholder="${this.escapeHtml(field.placeholder || '')}" disabled>`;
                    break;

                case 'textarea':
                    preview += `<textarea class="isf-preview-textarea" placeholder="${this.escapeHtml(field.placeholder || '')}" disabled></textarea>`;
                    break;

                case 'select':
                case 'state':
                case 'country':
                    preview += `<select class="isf-preview-select" disabled>
                        <option>${this.escapeHtml(field.placeholder || 'Select...')}</option>
                    </select>`;
                    break;

                case 'radio':
                    const radioOptions = field.options || [{ label: 'Option 1' }, { label: 'Option 2' }];
                    preview += '<div class="isf-preview-options">';
                    radioOptions.slice(0, 3).forEach((opt, i) => {
                        preview += `<label class="isf-preview-radio"><input type="radio" disabled ${i === 0 ? 'checked' : ''}> ${this.escapeHtml(opt.label || opt)}</label>`;
                    });
                    if (radioOptions.length > 3) preview += `<span class="isf-more-options">+${radioOptions.length - 3} more</span>`;
                    preview += '</div>';
                    break;

                case 'checkbox':
                    const checkOptions = field.options || [{ label: 'Option 1' }, { label: 'Option 2' }];
                    preview += '<div class="isf-preview-options">';
                    checkOptions.slice(0, 3).forEach((opt) => {
                        preview += `<label class="isf-preview-checkbox"><input type="checkbox" disabled> ${this.escapeHtml(opt.label || opt)}</label>`;
                    });
                    if (checkOptions.length > 3) preview += `<span class="isf-more-options">+${checkOptions.length - 3} more</span>`;
                    preview += '</div>';
                    break;

                case 'checkbox_single':
                    preview += `<label class="isf-preview-checkbox"><input type="checkbox" disabled> ${this.escapeHtml(field.checkbox_label || 'I agree')}</label>`;
                    break;

                case 'date':
                    preview += `<input type="text" class="isf-preview-input isf-preview-date" placeholder="MM/DD/YYYY" disabled><span class="dashicons dashicons-calendar-alt"></span>`;
                    break;

                case 'time':
                    preview += `<input type="text" class="isf-preview-input" placeholder="HH:MM" disabled><span class="dashicons dashicons-clock"></span>`;
                    break;

                case 'datetime':
                    preview += `<input type="text" class="isf-preview-input" placeholder="MM/DD/YYYY HH:MM" disabled><span class="dashicons dashicons-calendar"></span>`;
                    break;

                case 'file':
                    preview += `<div class="isf-preview-file"><span class="dashicons dashicons-upload"></span> Drop files or click to upload</div>`;
                    break;

                case 'signature':
                    preview += `<div class="isf-preview-signature"><span class="dashicons dashicons-art"></span> Click to sign</div>`;
                    break;

                case 'rating':
                    preview += `<div class="isf-preview-rating">`;
                    for (let i = 0; i < 5; i++) {
                        preview += `<span class="dashicons dashicons-star-empty"></span>`;
                    }
                    preview += `</div>`;
                    break;

                case 'slider':
                    preview += `<input type="range" class="isf-preview-slider" disabled>`;
                    break;

                case 'address':
                case 'service_address':
                    preview = `<label class="isf-preview-label">${this.escapeHtml(label)}${required}</label>`;
                    preview += `<div class="isf-preview-address">
                        <input type="text" class="isf-preview-input" placeholder="Street Address" disabled>
                        <div class="isf-preview-address-row">
                            <input type="text" placeholder="City" disabled>
                            <input type="text" placeholder="State" disabled>
                            <input type="text" placeholder="ZIP" disabled>
                        </div>
                    </div>`;
                    break;

                case 'program_selector':
                    preview += `<div class="isf-preview-programs">
                        <div class="isf-preview-program"><input type="checkbox" disabled> Program 1</div>
                        <div class="isf-preview-program"><input type="checkbox" disabled> Program 2</div>
                    </div>`;
                    break;

                case 'appointment_picker':
                    preview += `<div class="isf-preview-appointment">
                        <span class="dashicons dashicons-calendar"></span>
                        Select available date and time
                    </div>`;
                    break;

                case 'heading':
                    const headingLevel = field.heading_level || 'h2';
                    preview = `<${headingLevel} class="isf-preview-heading">${this.escapeHtml(field.content || 'Heading')}</${headingLevel}>`;
                    break;

                case 'paragraph':
                    preview = `<p class="isf-preview-paragraph">${this.escapeHtml(field.content || 'Paragraph text...')}</p>`;
                    break;

                case 'divider':
                    preview = `<hr class="isf-preview-divider">`;
                    break;

                case 'columns':
                    const colCount = field.columns || 2;
                    preview = `<div class="isf-preview-columns" style="--columns: ${colCount}">`;
                    for (let i = 0; i < colCount; i++) {
                        preview += `<div class="isf-preview-column">Column ${i + 1}</div>`;
                    }
                    preview += `</div>`;
                    break;

                case 'section':
                    preview = `<div class="isf-preview-section">
                        <div class="isf-preview-section-title">${this.escapeHtml(field.section_title || 'Section')}</div>
                        <div class="isf-preview-section-content">Section content area</div>
                    </div>`;
                    break;

                case 'html':
                    preview = `<div class="isf-preview-html"><span class="dashicons dashicons-html"></span> Custom HTML Block</div>`;
                    break;

                case 'hidden':
                    preview = `<div class="isf-preview-hidden"><span class="dashicons dashicons-hidden"></span> Hidden field: ${this.escapeHtml(field.name || 'field_name')}</div>`;
                    break;

                default:
                    preview += `<input type="text" class="isf-preview-input" disabled>`;
            }

            if (field.description) {
                preview += `<p class="isf-preview-description">${this.escapeHtml(field.description)}</p>`;
            }

            return preview;
        }

        /**
         * Get field configuration by type
         */
        getFieldConfig(type) {
            const categories = this.getFieldCategories();
            for (const category of Object.values(categories)) {
                const field = category.fields.find(f => f.type === type);
                if (field) return field;
            }
            return { type, label: type, icon: 'dashicons-admin-generic' };
        }

        /**
         * Add a new field to the current step
         */
        addField(type, insertIndex = null) {
            const step = this.schema.steps[this.currentStep];
            if (!step.fields) step.fields = [];

            const field = {
                id: this.generateId(),
                type: type,
                name: type + '_' + Date.now(),
                label: this.getFieldConfig(type).label,
                required: false,
                placeholder: '',
                description: ''
            };

            // Add type-specific defaults
            this.addFieldDefaults(field);

            if (insertIndex !== null && insertIndex < step.fields.length) {
                step.fields.splice(insertIndex, 0, field);
            } else {
                step.fields.push(field);
            }

            this.markDirty();
            this.saveHistory();
            this.renderStepContent();
            this.selectField(field.id);
        }

        /**
         * Add type-specific default values to a field
         */
        addFieldDefaults(field) {
            switch (field.type) {
                case 'select':
                case 'radio':
                case 'checkbox':
                    field.options = [
                        { value: 'option_1', label: 'Option 1' },
                        { value: 'option_2', label: 'Option 2' },
                        { value: 'option_3', label: 'Option 3' }
                    ];
                    break;

                case 'checkbox_single':
                    field.checkbox_label = 'I agree to the terms and conditions';
                    break;

                case 'rating':
                    field.max_rating = 5;
                    break;

                case 'slider':
                    field.min = 0;
                    field.max = 100;
                    field.step = 1;
                    break;

                case 'heading':
                    field.heading_level = 'h2';
                    field.content = 'Section Heading';
                    break;

                case 'paragraph':
                    field.content = 'Enter your text here...';
                    break;

                case 'columns':
                    field.columns = 2;
                    break;

                case 'section':
                    field.section_title = 'Section Title';
                    break;

                case 'program_selector':
                    field.allow_multiple = true;
                    field.show_descriptions = true;
                    break;
            }
        }

        /**
         * Delete a field
         */
        deleteField(fieldId) {
            const step = this.schema.steps[this.currentStep];
            const index = step.fields.findIndex(f => f.id === fieldId);

            if (index === -1) return;

            step.fields.splice(index, 1);

            // Remove any conditions referencing this field
            this.removeFieldConditions(fieldId);

            if (this.selectedField === fieldId) {
                this.deselectField();
            }

            this.markDirty();
            this.saveHistory();
            this.renderStepContent();
        }

        /**
         * Duplicate a field
         */
        duplicateField(fieldId) {
            const step = this.schema.steps[this.currentStep];
            const index = step.fields.findIndex(f => f.id === fieldId);

            if (index === -1) return;

            const original = step.fields[index];
            const duplicate = JSON.parse(JSON.stringify(original));
            duplicate.id = this.generateId();
            duplicate.name = original.name + '_copy';
            duplicate.label = original.label + ' (Copy)';

            step.fields.splice(index + 1, 0, duplicate);

            this.markDirty();
            this.saveHistory();
            this.renderStepContent();
            this.selectField(duplicate.id);
        }

        /**
         * Reorder fields after drag-drop
         */
        reorderFields() {
            const step = this.schema.steps[this.currentStep];
            const newOrder = [];

            this.container.find('.isf-step-content .isf-canvas-field').each(function() {
                const id = $(this).data('field-id');
                const field = step.fields.find(f => f.id === id);
                if (field) newOrder.push(field);
            });

            step.fields = newOrder;
            this.markDirty();
            this.saveHistory();
        }

        /**
         * Select a field for editing
         */
        selectField(fieldId) {
            this.selectedField = fieldId;

            // Update visual selection
            this.container.find('.isf-canvas-field').removeClass('selected');
            this.container.find(`.isf-canvas-field[data-field-id="${fieldId}"]`).addClass('selected');

            // Show properties panel
            this.renderPropertiesPanel(fieldId);
            this.container.find('.isf-builder-properties').addClass('active');
        }

        /**
         * Deselect the current field
         */
        deselectField() {
            this.selectedField = null;
            this.container.find('.isf-canvas-field').removeClass('selected');
            this.container.find('.isf-builder-properties').removeClass('active');
            this.container.find('.isf-properties-content').html(`
                <div class="isf-no-selection">
                    <span class="dashicons dashicons-forms"></span>
                    <p>Select a field to edit its properties</p>
                </div>
            `);
        }

        /**
         * Render properties panel for selected field
         */
        renderPropertiesPanel(fieldId) {
            const step = this.schema.steps[this.currentStep];
            const field = step.fields.find(f => f.id === fieldId);

            if (!field) return;

            const $content = this.container.find('.isf-properties-content');
            const fieldConfig = this.getFieldConfig(field.type);

            let html = `
                <div class="isf-properties-field">
                    <div class="isf-prop-header">
                        <span class="dashicons ${fieldConfig.icon}"></span>
                        <span>${this.escapeHtml(fieldConfig.label)}</span>
                    </div>

                    <div class="isf-prop-tabs">
                        <button type="button" class="isf-prop-tab active" data-tab="general">General</button>
                        <button type="button" class="isf-prop-tab" data-tab="validation">Validation</button>
                        <button type="button" class="isf-prop-tab" data-tab="advanced">Advanced</button>
                    </div>

                    <div class="isf-prop-tab-content" data-tab="general">
                        ${this.renderGeneralProperties(field)}
                    </div>

                    <div class="isf-prop-tab-content" data-tab="validation" style="display:none;">
                        ${this.renderValidationProperties(field)}
                    </div>

                    <div class="isf-prop-tab-content" data-tab="advanced" style="display:none;">
                        ${this.renderAdvancedProperties(field)}
                    </div>
                </div>
            `;

            $content.html(html);

            // Tab switching
            $content.find('.isf-prop-tab').on('click', function() {
                const tab = $(this).data('tab');
                $content.find('.isf-prop-tab').removeClass('active');
                $(this).addClass('active');
                $content.find('.isf-prop-tab-content').hide();
                $content.find(`.isf-prop-tab-content[data-tab="${tab}"]`).show();
            });

            // Initialize options editor if needed
            if (['select', 'radio', 'checkbox'].includes(field.type)) {
                this.initOptionsEditor(field);
            }
        }

        /**
         * Render general properties
         */
        renderGeneralProperties(field) {
            const isLayoutField = ['heading', 'paragraph', 'divider', 'columns', 'section', 'html'].includes(field.type);

            let html = `
                <div class="isf-prop-group">
                    <label class="isf-prop-label">Field Name</label>
                    <input type="text" class="isf-prop-input" data-prop="name" value="${this.escapeHtml(field.name || '')}">
                    <p class="isf-prop-help">Used for form submission (no spaces)</p>
                </div>
            `;

            if (!isLayoutField) {
                html += `
                    <div class="isf-prop-group">
                        <label class="isf-prop-label">Label</label>
                        <input type="text" class="isf-prop-input" data-prop="label" value="${this.escapeHtml(field.label || '')}">
                    </div>

                    <div class="isf-prop-group">
                        <label class="isf-prop-label">Placeholder</label>
                        <input type="text" class="isf-prop-input" data-prop="placeholder" value="${this.escapeHtml(field.placeholder || '')}">
                    </div>

                    <div class="isf-prop-group">
                        <label class="isf-prop-label">Description</label>
                        <textarea class="isf-prop-textarea" data-prop="description">${this.escapeHtml(field.description || '')}</textarea>
                    </div>

                    <div class="isf-prop-group">
                        <label class="isf-prop-checkbox">
                            <input type="checkbox" data-prop="required" ${field.required ? 'checked' : ''}>
                            Required field
                        </label>
                    </div>
                `;
            }

            // Type-specific properties
            html += this.renderTypeSpecificProperties(field);

            return html;
        }

        /**
         * Render type-specific properties
         */
        renderTypeSpecificProperties(field) {
            let html = '';

            switch (field.type) {
                case 'select':
                case 'radio':
                case 'checkbox':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Options</label>
                            <div class="isf-options-editor" data-field-id="${field.id}">
                                <div class="isf-options-list"></div>
                                <button type="button" class="isf-btn isf-btn-sm isf-btn-add-option">
                                    <span class="dashicons dashicons-plus"></span> Add Option
                                </button>
                            </div>
                        </div>
                    `;
                    break;

                case 'checkbox_single':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Checkbox Label</label>
                            <input type="text" class="isf-prop-input" data-prop="checkbox_label" value="${this.escapeHtml(field.checkbox_label || '')}">
                        </div>
                    `;
                    break;

                case 'number':
                case 'slider':
                    html = `
                        <div class="isf-prop-row">
                            <div class="isf-prop-group isf-prop-half">
                                <label class="isf-prop-label">Min</label>
                                <input type="number" class="isf-prop-input" data-prop="min" value="${field.min || ''}">
                            </div>
                            <div class="isf-prop-group isf-prop-half">
                                <label class="isf-prop-label">Max</label>
                                <input type="number" class="isf-prop-input" data-prop="max" value="${field.max || ''}">
                            </div>
                        </div>
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Step</label>
                            <input type="number" class="isf-prop-input" data-prop="step" value="${field.step || 1}">
                        </div>
                    `;
                    break;

                case 'rating':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Max Rating</label>
                            <select class="isf-prop-select" data-prop="max_rating">
                                <option value="5" ${field.max_rating == 5 ? 'selected' : ''}>5 Stars</option>
                                <option value="10" ${field.max_rating == 10 ? 'selected' : ''}>10 Stars</option>
                            </select>
                        </div>
                    `;
                    break;

                case 'file':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Allowed File Types</label>
                            <input type="text" class="isf-prop-input" data-prop="allowed_types" value="${this.escapeHtml(field.allowed_types || 'jpg,png,pdf')}" placeholder="jpg,png,pdf">
                        </div>
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Max File Size (MB)</label>
                            <input type="number" class="isf-prop-input" data-prop="max_size" value="${field.max_size || 5}">
                        </div>
                        <div class="isf-prop-group">
                            <label class="isf-prop-checkbox">
                                <input type="checkbox" data-prop="multiple" ${field.multiple ? 'checked' : ''}>
                                Allow multiple files
                            </label>
                        </div>
                    `;
                    break;

                case 'heading':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Heading Level</label>
                            <select class="isf-prop-select" data-prop="heading_level">
                                <option value="h1" ${field.heading_level === 'h1' ? 'selected' : ''}>H1</option>
                                <option value="h2" ${field.heading_level === 'h2' ? 'selected' : ''}>H2</option>
                                <option value="h3" ${field.heading_level === 'h3' ? 'selected' : ''}>H3</option>
                                <option value="h4" ${field.heading_level === 'h4' ? 'selected' : ''}>H4</option>
                            </select>
                        </div>
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Heading Text</label>
                            <input type="text" class="isf-prop-input" data-prop="content" value="${this.escapeHtml(field.content || '')}">
                        </div>
                    `;
                    break;

                case 'paragraph':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Content</label>
                            <textarea class="isf-prop-textarea" data-prop="content" rows="5">${this.escapeHtml(field.content || '')}</textarea>
                        </div>
                    `;
                    break;

                case 'columns':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Number of Columns</label>
                            <select class="isf-prop-select" data-prop="columns">
                                <option value="2" ${field.columns == 2 ? 'selected' : ''}>2 Columns</option>
                                <option value="3" ${field.columns == 3 ? 'selected' : ''}>3 Columns</option>
                                <option value="4" ${field.columns == 4 ? 'selected' : ''}>4 Columns</option>
                            </select>
                        </div>
                    `;
                    break;

                case 'section':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Section Title</label>
                            <input type="text" class="isf-prop-input" data-prop="section_title" value="${this.escapeHtml(field.section_title || '')}">
                        </div>
                        <div class="isf-prop-group">
                            <label class="isf-prop-checkbox">
                                <input type="checkbox" data-prop="collapsible" ${field.collapsible ? 'checked' : ''}>
                                Collapsible section
                            </label>
                        </div>
                    `;
                    break;

                case 'html':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">HTML Content</label>
                            <textarea class="isf-prop-textarea isf-prop-code" data-prop="html_content" rows="8">${this.escapeHtml(field.html_content || '')}</textarea>
                            <p class="isf-prop-help">Enter valid HTML. Scripts are not allowed.</p>
                        </div>
                    `;
                    break;

                case 'hidden':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Default Value</label>
                            <input type="text" class="isf-prop-input" data-prop="default_value" value="${this.escapeHtml(field.default_value || '')}">
                        </div>
                    `;
                    break;

                case 'date':
                case 'datetime':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-label">Date Format</label>
                            <select class="isf-prop-select" data-prop="date_format">
                                <option value="MM/DD/YYYY" ${field.date_format === 'MM/DD/YYYY' ? 'selected' : ''}>MM/DD/YYYY</option>
                                <option value="DD/MM/YYYY" ${field.date_format === 'DD/MM/YYYY' ? 'selected' : ''}>DD/MM/YYYY</option>
                                <option value="YYYY-MM-DD" ${field.date_format === 'YYYY-MM-DD' ? 'selected' : ''}>YYYY-MM-DD</option>
                            </select>
                        </div>
                        <div class="isf-prop-group">
                            <label class="isf-prop-checkbox">
                                <input type="checkbox" data-prop="disable_past" ${field.disable_past ? 'checked' : ''}>
                                Disable past dates
                            </label>
                        </div>
                    `;
                    break;

                case 'program_selector':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-checkbox">
                                <input type="checkbox" data-prop="allow_multiple" ${field.allow_multiple ? 'checked' : ''}>
                                Allow multiple program selection
                            </label>
                        </div>
                        <div class="isf-prop-group">
                            <label class="isf-prop-checkbox">
                                <input type="checkbox" data-prop="show_descriptions" ${field.show_descriptions ? 'checked' : ''}>
                                Show program descriptions
                            </label>
                        </div>
                        <div class="isf-prop-group">
                            <label class="isf-prop-checkbox">
                                <input type="checkbox" data-prop="show_cross_sell" ${field.show_cross_sell ? 'checked' : ''}>
                                Enable cross-sell recommendations
                            </label>
                        </div>
                    `;
                    break;

                case 'address':
                case 'service_address':
                    html = `
                        <div class="isf-prop-group">
                            <label class="isf-prop-checkbox">
                                <input type="checkbox" data-prop="enable_autocomplete" ${field.enable_autocomplete !== false ? 'checked' : ''}>
                                Enable address autocomplete
                            </label>
                        </div>
                        <div class="isf-prop-group">
                            <label class="isf-prop-checkbox">
                                <input type="checkbox" data-prop="validate_territory" ${field.validate_territory ? 'checked' : ''}>
                                Validate service territory
                            </label>
                        </div>
                    `;
                    break;
            }

            return html;
        }

        /**
         * Render validation properties
         */
        renderValidationProperties(field) {
            const isLayoutField = ['heading', 'paragraph', 'divider', 'columns', 'section', 'html'].includes(field.type);

            if (isLayoutField) {
                return '<p class="isf-prop-notice">Layout elements do not have validation options.</p>';
            }

            let html = `
                <div class="isf-prop-group">
                    <label class="isf-prop-checkbox">
                        <input type="checkbox" data-prop="required" ${field.required ? 'checked' : ''}>
                        Required field
                    </label>
                </div>
            `;

            // Text-based fields
            if (['text', 'textarea', 'email', 'phone', 'password'].includes(field.type)) {
                html += `
                    <div class="isf-prop-row">
                        <div class="isf-prop-group isf-prop-half">
                            <label class="isf-prop-label">Min Length</label>
                            <input type="number" class="isf-prop-input" data-prop="min_length" value="${field.min_length || ''}" min="0">
                        </div>
                        <div class="isf-prop-group isf-prop-half">
                            <label class="isf-prop-label">Max Length</label>
                            <input type="number" class="isf-prop-input" data-prop="max_length" value="${field.max_length || ''}" min="0">
                        </div>
                    </div>
                `;
            }

            // Custom validation pattern
            if (['text', 'phone', 'account_number', 'meter_number'].includes(field.type)) {
                html += `
                    <div class="isf-prop-group">
                        <label class="isf-prop-label">Validation Pattern (Regex)</label>
                        <input type="text" class="isf-prop-input" data-prop="pattern" value="${this.escapeHtml(field.pattern || '')}" placeholder="e.g., ^[0-9]{10}$">
                    </div>
                `;
            }

            // Custom error message
            html += `
                <div class="isf-prop-group">
                    <label class="isf-prop-label">Custom Error Message</label>
                    <input type="text" class="isf-prop-input" data-prop="error_message" value="${this.escapeHtml(field.error_message || '')}" placeholder="This field is required">
                </div>
            `;

            return html;
        }

        /**
         * Render advanced properties
         */
        renderAdvancedProperties(field) {
            return `
                <div class="isf-prop-group">
                    <label class="isf-prop-label">CSS Classes</label>
                    <input type="text" class="isf-prop-input" data-prop="css_class" value="${this.escapeHtml(field.css_class || '')}" placeholder="custom-class another-class">
                </div>

                <div class="isf-prop-group">
                    <label class="isf-prop-label">Field ID</label>
                    <input type="text" class="isf-prop-input" data-prop="custom_id" value="${this.escapeHtml(field.custom_id || '')}" placeholder="Leave empty for auto-generated">
                </div>

                <div class="isf-prop-group">
                    <label class="isf-prop-label">Default Value</label>
                    <input type="text" class="isf-prop-input" data-prop="default_value" value="${this.escapeHtml(field.default_value || '')}">
                </div>

                <div class="isf-prop-group">
                    <label class="isf-prop-checkbox">
                        <input type="checkbox" data-prop="readonly" ${field.readonly ? 'checked' : ''}>
                        Read only
                    </label>
                </div>

                <div class="isf-prop-group">
                    <label class="isf-prop-checkbox">
                        <input type="checkbox" data-prop="disabled" ${field.disabled ? 'checked' : ''}>
                        Disabled
                    </label>
                </div>

                <div class="isf-prop-group">
                    <label class="isf-prop-label">Conditional Logic</label>
                    <button type="button" class="isf-btn isf-btn-secondary isf-btn-edit-conditions" data-field-id="${field.id}">
                        <span class="dashicons dashicons-randomize"></span>
                        ${this.fieldHasConditions(field.id) ? 'Edit Conditions' : 'Add Conditions'}
                    </button>
                </div>
            `;
        }

        /**
         * Initialize options editor for select/radio/checkbox fields
         */
        initOptionsEditor(field) {
            const $editor = this.container.find(`.isf-options-editor[data-field-id="${field.id}"]`);
            const $list = $editor.find('.isf-options-list');

            // Render existing options
            this.renderOptions($list, field);

            // Make options sortable
            $list.sortable({
                handle: '.isf-option-drag',
                placeholder: 'isf-option-placeholder',
                update: () => this.updateOptionsFromEditor($list, field)
            });

            // Add option button
            $editor.find('.isf-btn-add-option').on('click', () => {
                const newOption = {
                    value: 'option_' + (field.options.length + 1),
                    label: 'Option ' + (field.options.length + 1)
                };
                field.options.push(newOption);
                this.renderOptions($list, field);
                this.markDirty();
                this.renderStepContent();
            });
        }

        /**
         * Render options list
         */
        renderOptions($list, field) {
            let html = '';

            (field.options || []).forEach((opt, index) => {
                html += `
                    <div class="isf-option-item" data-index="${index}">
                        <span class="isf-option-drag dashicons dashicons-menu"></span>
                        <input type="text" class="isf-option-label" value="${this.escapeHtml(opt.label || '')}" placeholder="Label">
                        <input type="text" class="isf-option-value" value="${this.escapeHtml(opt.value || '')}" placeholder="Value">
                        <button type="button" class="isf-option-remove" title="Remove">&times;</button>
                    </div>
                `;
            });

            $list.html(html);

            // Bind events
            const self = this;
            $list.find('.isf-option-label, .isf-option-value').on('input', function() {
                self.updateOptionsFromEditor($list, field);
            });

            $list.find('.isf-option-remove').on('click', function() {
                const index = $(this).closest('.isf-option-item').data('index');
                field.options.splice(index, 1);
                self.renderOptions($list, field);
                self.markDirty();
                self.renderStepContent();
            });
        }

        /**
         * Update options from editor inputs
         */
        updateOptionsFromEditor($list, field) {
            field.options = [];

            $list.find('.isf-option-item').each(function() {
                field.options.push({
                    label: $(this).find('.isf-option-label').val(),
                    value: $(this).find('.isf-option-value').val()
                });
            });

            this.markDirty();
            this.renderStepContent();
        }

        /**
         * Update a field property from the properties panel
         */
        updateFieldProperty(e) {
            if (!this.selectedField) return;

            const $input = $(e.target);
            const prop = $input.data('prop');
            if (!prop) return;

            const step = this.schema.steps[this.currentStep];
            const field = step.fields.find(f => f.id === this.selectedField);
            if (!field) return;

            // Get value based on input type
            let value;
            if ($input.attr('type') === 'checkbox') {
                value = $input.is(':checked');
            } else if ($input.attr('type') === 'number') {
                value = $input.val() ? parseFloat($input.val()) : null;
            } else {
                value = $input.val();
            }

            field[prop] = value;

            // Update field name to be slug-friendly
            if (prop === 'name') {
                field.name = value.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
                $input.val(field.name);
            }

            this.markDirty();
            this.renderStepContent();
        }

        /**
         * Switch to a different step
         */
        switchStep(index) {
            if (index < 0 || index >= this.schema.steps.length) return;

            this.currentStep = index;
            this.deselectField();
            this.render();
        }

        /**
         * Add a new step
         */
        addStep() {
            const newStep = {
                id: 'step_' + this.generateId(),
                title: 'Step ' + (this.schema.steps.length + 1),
                description: '',
                fields: []
            };

            this.schema.steps.push(newStep);
            this.currentStep = this.schema.steps.length - 1;

            this.markDirty();
            this.saveHistory();
            this.render();
        }

        /**
         * Delete a step
         */
        deleteStep(index) {
            if (this.schema.steps.length <= 1) {
                alert('You must have at least one step.');
                return;
            }

            if (!confirm('Are you sure you want to delete this step and all its fields?')) {
                return;
            }

            // Remove conditions for all fields in this step
            const step = this.schema.steps[index];
            step.fields.forEach(field => this.removeFieldConditions(field.id));

            this.schema.steps.splice(index, 1);

            if (this.currentStep >= this.schema.steps.length) {
                this.currentStep = this.schema.steps.length - 1;
            }

            this.markDirty();
            this.saveHistory();
            this.render();
        }

        /**
         * Filter fields in palette by search term
         */
        filterFields(search) {
            const term = search.toLowerCase().trim();

            this.container.find('.isf-palette-field').each(function() {
                const label = $(this).find('.isf-field-label').text().toLowerCase();
                const type = $(this).data('type').toLowerCase();
                const visible = !term || label.includes(term) || type.includes(term);
                $(this).toggle(visible);
            });

            // Show/hide empty categories
            this.container.find('.isf-palette-category').each(function() {
                const hasVisible = $(this).find('.isf-palette-field:visible').length > 0;
                $(this).toggle(hasVisible);
            });
        }

        /**
         * Check if a field has conditions
         */
        fieldHasConditions(fieldId) {
            return (this.schema.conditions || []).some(c =>
                c.target_field === fieldId ||
                (c.conditions || []).some(cond => cond.field === fieldId)
            );
        }

        /**
         * Remove conditions for a field
         */
        removeFieldConditions(fieldId) {
            if (!this.schema.conditions) return;

            // Remove conditions targeting this field
            this.schema.conditions = this.schema.conditions.filter(c => c.target_field !== fieldId);

            // Remove this field from other conditions
            this.schema.conditions.forEach(rule => {
                if (rule.conditions) {
                    rule.conditions = rule.conditions.filter(c => c.field !== fieldId);
                }
            });

            // Clean up empty rules
            this.schema.conditions = this.schema.conditions.filter(c =>
                c.conditions && c.conditions.length > 0
            );
        }

        /**
         * Open conditions modal for a field
         */
        openConditionsModal(fieldId) {
            const $modal = this.container.find('.isf-modal-conditions');
            const $body = $modal.find('.isf-modal-body');

            // Get all fields for dropdown
            const allFields = [];
            this.schema.steps.forEach(step => {
                (step.fields || []).forEach(f => {
                    if (f.id !== fieldId) {
                        allFields.push({ id: f.id, label: f.label || f.name, type: f.type });
                    }
                });
            });

            // Get existing conditions for this field
            const existingRule = (this.schema.conditions || []).find(c => c.target_field === fieldId);
            const conditions = existingRule ? existingRule.conditions : [];

            let html = `
                <div class="isf-conditions-builder" data-target-field="${fieldId}">
                    <p class="isf-conditions-intro">
                        <strong>Show this field when:</strong>
                    </p>

                    <div class="isf-conditions-list">
                        ${conditions.length ? '' : '<div class="isf-condition-empty">No conditions added yet.</div>'}
                    </div>

                    <button type="button" class="isf-btn isf-btn-sm isf-btn-add-condition">
                        <span class="dashicons dashicons-plus"></span> Add Condition
                    </button>

                    ${conditions.length > 1 ? `
                        <div class="isf-conditions-logic">
                            <label>Match:</label>
                            <select class="isf-condition-logic-select">
                                <option value="all" ${existingRule?.logic === 'all' ? 'selected' : ''}>All conditions (AND)</option>
                                <option value="any" ${existingRule?.logic === 'any' ? 'selected' : ''}>Any condition (OR)</option>
                            </select>
                        </div>
                    ` : ''}

                    <div class="isf-conditions-action">
                        <label>Action when conditions are met:</label>
                        <select class="isf-condition-action-select">
                            <option value="show" ${!existingRule || existingRule.action === 'show' ? 'selected' : ''}>Show field</option>
                            <option value="hide" ${existingRule?.action === 'hide' ? 'selected' : ''}>Hide field</option>
                            <option value="enable" ${existingRule?.action === 'enable' ? 'selected' : ''}>Enable field</option>
                            <option value="disable" ${existingRule?.action === 'disable' ? 'selected' : ''}>Disable field</option>
                            <option value="require" ${existingRule?.action === 'require' ? 'selected' : ''}>Make required</option>
                        </select>
                    </div>
                </div>
            `;

            $body.html(html);

            // Render existing conditions
            const $list = $body.find('.isf-conditions-list');
            conditions.forEach((cond, index) => {
                $list.append(this.renderConditionRow(cond, index, allFields));
            });

            if (conditions.length) {
                $list.find('.isf-condition-empty').remove();
            }

            // Bind add condition
            $body.find('.isf-btn-add-condition').on('click', () => {
                $list.find('.isf-condition-empty').remove();
                const index = $list.find('.isf-condition-row').length;
                $list.append(this.renderConditionRow({}, index, allFields));
            });

            // Bind remove condition
            $body.on('click', '.isf-condition-remove', function() {
                $(this).closest('.isf-condition-row').remove();
                if ($list.find('.isf-condition-row').length === 0) {
                    $list.html('<div class="isf-condition-empty">No conditions added yet.</div>');
                }
            });

            // Update operator options when field changes
            $body.on('change', '.isf-condition-field', function() {
                const $row = $(this).closest('.isf-condition-row');
                const selectedField = allFields.find(f => f.id === $(this).val());
                if (selectedField) {
                    $row.find('.isf-condition-operator').html(
                        this.getOperatorOptions(selectedField.type)
                    );
                }
            }.bind(this));

            $modal.show();
        }

        /**
         * Render a condition row
         */
        renderConditionRow(condition, index, allFields) {
            const fieldOptions = allFields.map(f =>
                `<option value="${f.id}" ${condition.field === f.id ? 'selected' : ''}>${this.escapeHtml(f.label)}</option>`
            ).join('');

            const selectedField = allFields.find(f => f.id === condition.field);
            const operatorOptions = this.getOperatorOptions(selectedField?.type);

            return `
                <div class="isf-condition-row" data-index="${index}">
                    <select class="isf-condition-field">
                        <option value="">Select field...</option>
                        ${fieldOptions}
                    </select>
                    <select class="isf-condition-operator">
                        ${operatorOptions}
                    </select>
                    <input type="text" class="isf-condition-value" value="${this.escapeHtml(condition.value || '')}" placeholder="Value">
                    <button type="button" class="isf-condition-remove" title="Remove">&times;</button>
                </div>
            `;
        }

        /**
         * Get operator options based on field type
         */
        getOperatorOptions(fieldType) {
            const operators = [
                { value: 'equals', label: 'Equals' },
                { value: 'not_equals', label: 'Does not equal' },
                { value: 'contains', label: 'Contains' },
                { value: 'not_contains', label: 'Does not contain' },
                { value: 'is_empty', label: 'Is empty' },
                { value: 'is_not_empty', label: 'Is not empty' }
            ];

            // Add numeric operators for appropriate types
            if (['number', 'slider', 'rating'].includes(fieldType)) {
                operators.push(
                    { value: 'greater_than', label: 'Greater than' },
                    { value: 'less_than', label: 'Less than' },
                    { value: 'greater_equal', label: 'Greater or equal' },
                    { value: 'less_equal', label: 'Less or equal' }
                );
            }

            // Add checkbox operator
            if (['checkbox_single', 'checkbox'].includes(fieldType)) {
                operators.unshift({ value: 'is_checked', label: 'Is checked' });
            }

            return operators.map(op =>
                `<option value="${op.value}">${op.label}</option>`
            ).join('');
        }

        /**
         * Save conditions from modal
         */
        saveConditions() {
            const $builder = this.container.find('.isf-conditions-builder');
            const targetField = $builder.data('target-field');

            // Gather conditions
            const conditions = [];
            $builder.find('.isf-condition-row').each(function() {
                const field = $(this).find('.isf-condition-field').val();
                const operator = $(this).find('.isf-condition-operator').val();
                const value = $(this).find('.isf-condition-value').val();

                if (field) {
                    conditions.push({ field, operator, value });
                }
            });

            // Initialize conditions array if needed
            if (!this.schema.conditions) {
                this.schema.conditions = [];
            }

            // Remove existing rule for this field
            this.schema.conditions = this.schema.conditions.filter(c => c.target_field !== targetField);

            // Add new rule if there are conditions
            if (conditions.length > 0) {
                this.schema.conditions.push({
                    target_field: targetField,
                    action: $builder.find('.isf-condition-action-select').val() || 'show',
                    logic: $builder.find('.isf-condition-logic-select').val() || 'all',
                    conditions: conditions
                });
            }

            this.closeModals();
            this.markDirty();
            this.saveHistory();
            this.renderStepContent();
        }

        /**
         * Show preview modal
         */
        showPreview() {
            const $modal = this.container.find('.isf-modal-preview');
            const $frame = $modal.find('.isf-preview-frame');

            // Load preview via AJAX
            $frame.html('<div class="isf-preview-loading"><span class="dashicons dashicons-update isf-spin"></span> Loading preview...</div>');
            $modal.show();

            $.ajax({
                url: isf_builder.ajax_url,
                type: 'POST',
                data: {
                    action: 'formflow_builder_preview',
                    nonce: isf_builder.nonce,
                    schema: JSON.stringify(this.schema)
                },
                success: (response) => {
                    if (response.success) {
                        $frame.html(response.data.html);
                    } else {
                        $frame.html('<div class="isf-preview-error">Failed to load preview</div>');
                    }
                },
                error: () => {
                    $frame.html('<div class="isf-preview-error">Failed to load preview</div>');
                }
            });
        }

        /**
         * Close all modals
         */
        closeModals() {
            this.container.find('.isf-modal').hide();
        }

        /**
         * Save the form schema
         */
        save() {
            const $status = this.container.find('.isf-save-status');
            const $btn = this.container.find('.isf-btn-save');

            $status.text('Saving...').addClass('saving');
            $btn.prop('disabled', true);

            $.ajax({
                url: isf_builder.ajax_url,
                type: 'POST',
                data: {
                    action: 'formflow_builder_save',
                    nonce: isf_builder.nonce,
                    instance_id: this.instanceId,
                    schema: JSON.stringify(this.schema)
                },
                success: (response) => {
                    if (response.success) {
                        $status.text('Saved').removeClass('saving').addClass('saved');
                        this.isDirty = false;

                        setTimeout(() => {
                            $status.text('').removeClass('saved');
                        }, 2000);
                    } else {
                        $status.text('Save failed').removeClass('saving').addClass('error');
                        alert(response.data.message || 'Failed to save form');
                    }
                },
                error: () => {
                    $status.text('Save failed').removeClass('saving').addClass('error');
                    alert('Failed to save form. Please try again.');
                },
                complete: () => {
                    $btn.prop('disabled', false);
                }
            });
        }

        /**
         * Mark the form as dirty (unsaved changes)
         */
        markDirty() {
            this.isDirty = true;
            this.container.find('.isf-save-status').text('Unsaved changes').addClass('dirty');
        }

        /**
         * Save current state to history for undo/redo
         */
        saveHistory() {
            // Remove any redo states
            this.history = this.history.slice(0, this.historyIndex + 1);

            // Add current state
            this.history.push(JSON.stringify(this.schema));

            // Limit history size
            if (this.history.length > this.maxHistory) {
                this.history.shift();
            }

            this.historyIndex = this.history.length - 1;
            this.updateUndoRedoButtons();
        }

        /**
         * Undo last change
         */
        undo() {
            if (this.historyIndex <= 0) return;

            this.historyIndex--;
            this.schema = JSON.parse(this.history[this.historyIndex]);
            this.markDirty();
            this.render();
            this.updateUndoRedoButtons();
        }

        /**
         * Redo last undone change
         */
        redo() {
            if (this.historyIndex >= this.history.length - 1) return;

            this.historyIndex++;
            this.schema = JSON.parse(this.history[this.historyIndex]);
            this.markDirty();
            this.render();
            this.updateUndoRedoButtons();
        }

        /**
         * Update undo/redo button states
         */
        updateUndoRedoButtons() {
            this.container.find('.isf-btn-undo').prop('disabled', this.historyIndex <= 0);
            this.container.find('.isf-btn-redo').prop('disabled', this.historyIndex >= this.history.length - 1);
        }

        /**
         * Generate a unique ID
         */
        generateId() {
            return 'f' + Math.random().toString(36).substr(2, 9);
        }

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml(str) {
            if (str === null || str === undefined) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    }

    // Export FormBuilder class
    window.ISFFormBuilder.FormBuilder = FormBuilder;

    // Auto-initialize on page load if container exists
    $(document).ready(function() {
        if ($('#isf-form-builder').length && typeof isf_builder !== 'undefined') {
            window.ISFFormBuilder.instance = new FormBuilder({
                container: '#isf-form-builder',
                instanceId: isf_builder.instance_id || 0,
                schema: isf_builder.schema || null,
                fieldTypes: isf_builder.field_types || {}
            });
        }
    });

})(jQuery);
