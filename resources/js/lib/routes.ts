// Fonction route simple pour remplacer Ziggy temporairement
export function route(name: string, params: any = {}) {
    const routes: Record<string, string> = {
        'dashboard': '/dashboard',
        'products.index': '/products',
        'products.create': '/products/create',
        'products.store': '/products',
        'products.show': '/products/{id}',
        'products.edit': '/products/{id}/edit',
        'products.update': '/products/{id}',
        'products.destroy': '/products/{id}',
        'products.generate-sku': '/products/generate-sku',
        'products.upload-image': '/products/upload-image',
        'categories.index': '/categories',
        'categories.create': '/categories/create',
        'categories.store': '/categories',
        'categories.show': '/categories/{id}',
        'categories.edit': '/categories/{id}/edit',
        'categories.update': '/categories/{id}',
        'categories.destroy': '/categories/{id}',
        'customers.index': '/customers',
        'customers.create': '/customers/create',
        'customers.store': '/customers',
        'customers.show': '/customers/{id}',
        'customers.edit': '/customers/{id}/edit',
        'customers.update': '/customers/{id}',
        'customers.destroy': '/customers/{id}',
        'customers.export.excel': '/customers/export/excel',
        'customers.export.pdf': '/customers/export/pdf',
        'sales.index': '/sales',
        'sales.create': '/sales/create',
        'sales.store': '/sales',
        'sales.show': '/sales/{id}',
        'sales.edit': '/sales/{id}/edit',
        'sales.update': '/sales/{id}',
        'sales.destroy': '/sales/{id}',
        'sales.invoice.download': '/sales/{sale}/invoice/download',
        'sales.invoice.print': '/sales/{sale}/invoice/print',
        'quotes.index': '/quotes',
        'quotes.create': '/quotes/create',
        'quotes.store': '/quotes',
        'quotes.show': '/quotes/{id}',
        'quotes.edit': '/quotes/{id}/edit',
        'quotes.update': '/quotes/{id}',
        'quotes.destroy': '/quotes/{id}',
        'quotes.download': '/quotes/{quote}/download',
        'quotes.print': '/quotes/{quote}/print',
        'expenses.index': '/expenses',
        'expenses.create': '/expenses/create',
        'expenses.store': '/expenses',
        'expenses.show': '/expenses/{id}',
        'expenses.edit': '/expenses/{id}/edit',
        'expenses.update': '/expenses/{id}',
        'expenses.destroy': '/expenses/{id}',
        'suppliers.index': '/suppliers',
        'suppliers.create': '/suppliers/create',
        'suppliers.store': '/suppliers',
        'suppliers.show': '/suppliers/{id}',
        'suppliers.edit': '/suppliers/{id}/edit',
        'suppliers.update': '/suppliers/{id}',
        'suppliers.destroy': '/suppliers/{id}',
        'suppliers.export.excel': '/suppliers/export/excel',
        'suppliers.export.pdf': '/suppliers/export/pdf',
        'purchase-orders.index': '/purchase-orders',
        'purchase-orders.create': '/purchase-orders/create',
        'purchase-orders.store': '/purchase-orders',
        'purchase-orders.show': '/purchase-orders/{id}',
        'purchase-orders.edit': '/purchase-orders/{id}/edit',
        'purchase-orders.update': '/purchase-orders/{id}',
        'purchase-orders.destroy': '/purchase-orders/{id}',
        'purchase-orders.download': '/purchase-orders/{purchaseOrder}/download',
        'purchase-orders.print': '/purchase-orders/{purchaseOrder}/print',
        'delivery-notes.index': '/delivery-notes',
        'delivery-notes.create': '/delivery-notes/create',
        'delivery-notes.store': '/delivery-notes',
        'delivery-notes.show': '/delivery-notes/{id}',
        'delivery-notes.edit': '/delivery-notes/{id}/edit',
        'delivery-notes.update': '/delivery-notes/{id}',
        'delivery-notes.destroy': '/delivery-notes/{id}',
        'delivery-notes.validate': '/delivery-notes/{deliveryNote}/validate',
        'delivery-notes.download': '/delivery-notes/{deliveryNote}/download',
        'delivery-notes.print': '/delivery-notes/{deliveryNote}/print',
        // Fichier facture/BL fournisseur
        'delivery-notes.invoice.upload': '/delivery-notes/{deliveryNote}/invoice',
        'delivery-notes.invoice.show': '/delivery-notes/{deliveryNote}/invoice',
        'delivery-notes.invoice.delete': '/delivery-notes/{deliveryNote}/invoice',
        'company.edit': '/company',
        'company.update': '/company',
        'profile.edit': '/settings/profile',
        'profile.update': '/settings/profile',
        'profile.destroy': '/settings/profile',
        'password.edit': '/settings/password',
        'password.update': '/settings/password',
        'password.store': '/reset-password',
        'login': '/login',
        'logout': '/logout',
        'register': '/register',
        'verification.notice': '/verify-email',
        'verification.send': '/email/verification-notification',
        'home': '/',
               'notifications.mark-as-read': '/notifications/mark-as-read',
               'notifications.mark-all-as-read': '/notifications/mark-all-as-read',
               'notifications.test': '/notifications/test',
        // Routes admin
        'admin.users.index': '/admin/users',
        'admin.users.create': '/admin/users/create',
        'admin.users.store': '/admin/users',
        'admin.users.show': '/admin/users/{user}',
        'admin.users.edit': '/admin/users/{user}/edit',
        'admin.users.update': '/admin/users/{user}',
        'admin.users.destroy': '/admin/users/{user}',
        'admin.backups.index': '/admin/backups',
        'admin.backups.store': '/admin/backups',
        'admin.backups.destroy': '/admin/backups/{backup}',
        'admin.backups.download': '/admin/backups/{backup}/download',
        'admin.backups.restore': '/admin/backups/{backup}/restore',
        'admin.backups.import': '/admin/backups/import',
    };

    let url = routes[name];
    if (!url) {
        return '#';
    }

    // Si params est un nombre ou une string, le convertir en objet avec la première clé trouvée
    if (typeof params === 'number' || typeof params === 'string') {
        // Trouver le premier paramètre dans le template (ex: {id}, {user})
        const match = url.match(/\{(\w+)\}/);
        if (match) {
            params = { [match[1]]: params };
        } else {
            params = { id: params }; // Fallback par défaut
        }
    }

    // Séparer les paramètres de route et les paramètres de requête
    const routeParams: Record<string, any> = {};
    const queryParams: Record<string, any> = {};
    
    Object.keys(params).forEach(key => {
        const value = params[key];
        if (value !== null && value !== undefined) {
            // Si le paramètre est dans l'URL (ex: {id}), c'est un paramètre de route
            if (url.includes(`{${key}}`)) {
                routeParams[key] = value;
            } else {
                // Sinon, c'est un paramètre de requête
                queryParams[key] = value;
            }
        }
    });

    // Remplacer les paramètres dans l'URL
    Object.keys(routeParams).forEach(key => {
        url = url.replace(`{${key}}`, String(routeParams[key]));
    });

    // Ajouter les paramètres de requête à l'URL
    if (Object.keys(queryParams).length > 0) {
        const queryString = Object.keys(queryParams)
            .map(key => `${encodeURIComponent(key)}=${encodeURIComponent(queryParams[key])}`)
            .join('&');
        url += `?${queryString}`;
    }

    return url;
}

// Fonction pour générer des URLs avec paramètres
export function routeWithParams(name: string, params: any = {}) {
    return route(name, params);
}
