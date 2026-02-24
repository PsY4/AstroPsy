(function () {
    'use strict';

    var container = document.getElementById('template-editor');
    if (!container) return;

    var hiddenInput = document.getElementById('template-json');
    var roles = JSON.parse(container.dataset.roles || '[]');
    var defaultTemplate = JSON.parse(container.dataset.defaultTemplate || '{}');
    var tree = JSON.parse(hiddenInput.value || '{}');

    // Role category for badge coloring
    var roleCategory = {
        LIGHT: 'capture', DARK: 'calib', BIAS: 'calib', FLAT: 'calib',
        MASTER: 'process', EXPORT: 'process',
        LOG_NINA: 'log', LOG_PHD2: 'log', DOC: 'doc'
    };

    function renderTree(nodes, parentUl) {
        parentUl.innerHTML = '';
        nodes.forEach(function (node, idx) {
            var li = document.createElement('li');
            li.className = 'template-node';

            var row = document.createElement('div');
            row.className = 'template-node-row';

            // Collapse toggle (only if has children)
            var hasChildren = node.children && node.children.length > 0;
            if (hasChildren) {
                var toggle = document.createElement('span');
                toggle.className = 'template-toggle';
                toggle.innerHTML = '<i class="fa fa-chevron-down"></i>';
                toggle.addEventListener('click', function () {
                    var childUl = li.querySelector(':scope > .template-tree');
                    if (childUl) {
                        childUl.classList.toggle('collapsed');
                        toggle.classList.toggle('collapsed');
                    }
                });
                row.appendChild(toggle);
            } else {
                var spacer = document.createElement('span');
                spacer.className = 'template-toggle-spacer';
                row.appendChild(spacer);
            }

            // Folder icon
            var icon = document.createElement('i');
            icon.className = 'fa template-folder-icon';
            if (node.role) {
                icon.classList.add('fa-folder-open', 'text-warning');
            } else if (hasChildren) {
                icon.classList.add('fa-folder', 'text-warning');
            } else {
                icon.classList.add('fa-folder', 'text-muted');
            }
            row.appendChild(icon);

            // Name input
            var nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.className = 'form-control form-control-sm template-name-input';
            nameInput.value = node.name || '';
            nameInput.placeholder = 'Folder name';
            nameInput.addEventListener('input', function () {
                node.name = this.value;
                serialize();
            });
            row.appendChild(nameInput);

            // Role select
            var roleSelect = document.createElement('select');
            roleSelect.className = 'form-select form-select-sm template-role-select';
            var emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '--';
            roleSelect.appendChild(emptyOpt);
            roles.forEach(function (r) {
                var opt = document.createElement('option');
                opt.value = r;
                opt.textContent = r;
                if (node.role === r) opt.selected = true;
                roleSelect.appendChild(opt);
            });
            roleSelect.addEventListener('change', function () {
                node.role = this.value || undefined;
                if (!node.role) delete node.role;
                serialize();
                renderAll();
            });
            row.appendChild(roleSelect);

            // Role badge (if assigned)
            if (node.role) {
                var badge = document.createElement('span');
                var cat = roleCategory[node.role] || 'log';
                badge.className = 'template-role-badge role-' + cat;
                badge.textContent = node.role;
                row.appendChild(badge);
            }

            // allowExtra toggle icon
            var extraIcon = document.createElement('span');
            extraIcon.className = 'template-extra-icon' + (node.allowExtra ? ' active' : '');
            extraIcon.innerHTML = '<i class="fa fa-folder-plus"></i>';
            extraIcon.title = container.dataset.msgAllowExtra || 'Allow extra subdirectories';
            extraIcon.addEventListener('click', function () {
                if (node.allowExtra) {
                    delete node.allowExtra;
                    extraIcon.classList.remove('active');
                } else {
                    node.allowExtra = true;
                    extraIcon.classList.add('active');
                }
                serialize();
            });
            row.appendChild(extraIcon);

            // Action buttons (right-aligned, appear on hover)
            var actions = document.createElement('span');
            actions.className = 'template-actions';

            var addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'btn btn-outline-secondary';
            addBtn.innerHTML = '<i class="fa fa-plus"></i>';
            addBtn.title = 'Add child';
            addBtn.addEventListener('click', function () {
                if (!node.children) node.children = [];
                node.children.push({ name: '', children: [] });
                renderAll();
                serialize();
            });

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-outline-danger';
            removeBtn.innerHTML = '<i class="fa fa-trash"></i>';
            removeBtn.title = 'Remove';
            removeBtn.addEventListener('click', function () {
                nodes.splice(idx, 1);
                renderAll();
                serialize();
            });

            actions.appendChild(addBtn);
            actions.appendChild(removeBtn);
            row.appendChild(actions);

            li.appendChild(row);

            // Children
            if (hasChildren) {
                var childUl = document.createElement('ul');
                childUl.className = 'template-tree';
                renderTree(node.children, childUl);
                li.appendChild(childUl);
            }

            parentUl.appendChild(li);
        });
    }

    function renderAll() {
        var rootUl = container.querySelector('.template-tree');
        if (!rootUl) {
            rootUl = document.createElement('ul');
            rootUl.className = 'template-tree';
            container.insertBefore(rootUl, container.firstChild);
        }
        renderTree(tree.tree || [], rootUl);
        highlightRoles();
    }

    function serialize() {
        hiddenInput.value = JSON.stringify(tree);
    }

    function highlightRoles() {
        var assigned = {};
        collectAssigned(tree.tree || [], assigned);

        var statusEl = document.getElementById('template-roles-status');
        if (!statusEl) return;

        var missing = roles.filter(function (r) { return !assigned[r]; });
        var duplicates = Object.keys(assigned).filter(function (r) { return assigned[r] > 1; });

        if (missing.length === 0 && duplicates.length === 0) {
            statusEl.className = 'alert alert-success py-1 px-2 small mb-3';
            statusEl.innerHTML = '<i class="fa fa-circle-check me-1"></i>' + (container.dataset.msgValid || 'All 9 roles assigned.');
        } else {
            var msgs = [];
            if (missing.length > 0) {
                msgs.push('<i class="fa fa-triangle-exclamation me-1"></i>' + (container.dataset.msgMissing || 'Missing roles') + ': <strong>' + missing.join(', ') + '</strong>');
            }
            if (duplicates.length > 0) {
                msgs.push('<i class="fa fa-triangle-exclamation me-1"></i>' + (container.dataset.msgDuplicate || 'Duplicate roles') + ': <strong>' + duplicates.join(', ') + '</strong>');
            }
            statusEl.className = 'alert alert-warning py-1 px-2 small mb-3';
            statusEl.innerHTML = msgs.join(' &mdash; ');
        }

        // Enable/disable submit
        var submitBtn = document.getElementById('template-save-btn');
        if (submitBtn) {
            submitBtn.disabled = (missing.length > 0 || duplicates.length > 0);
        }
    }

    function collectAssigned(nodes, map) {
        nodes.forEach(function (n) {
            if (n.role) {
                map[n.role] = (map[n.role] || 0) + 1;
            }
            if (n.children) collectAssigned(n.children, map);
        });
    }

    // Add root node button
    var addRootBtn = document.getElementById('template-add-root');
    if (addRootBtn) {
        addRootBtn.addEventListener('click', function () {
            if (!tree.tree) tree.tree = [];
            tree.tree.push({ name: '', children: [] });
            renderAll();
            serialize();
        });
    }

    // Reset button
    var resetBtn = document.getElementById('template-reset');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            tree = JSON.parse(JSON.stringify(defaultTemplate));
            hiddenInput.value = JSON.stringify(tree);
            renderAll();
        });
    }

    renderAll();
    serialize();
})();
