# Déploiement Pterodactyl Panel sur Railway

## Configuration requise

### Variables d'environnement obligatoires

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-app.railway.app
APP_TIMEZONE=UTC
APP_LOCALE=en

DB_HOST=votre-db-host
DB_PORT=3306
DB_DATABASE=pterodactyl
DB_USERNAME=pterodactyl
DB_PASSWORD=votre-mot-de-passe

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=votre-redis-host
REDIS_PORT=6379
REDIS_PASSWORD=votre-redis-password

MAIL_DRIVER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=votre-email
MAIL_PASSWORD=votre-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=Pterodactyl
```

## Services nécessaires

1. **Base de données MySQL** (version 8.0+)
2. **Redis** (pour cache et sessions)
3. **Service SMTP** (pour les emails)

## Étapes de déploiement

1. Créer un nouveau projet sur Railway
2. Connecter votre repository GitHub
3. Ajouter un service MySQL depuis Railway
4. Ajouter un service Redis depuis Railway
5. Configurer les variables d'environnement
6. Déployer

## Configuration post-déploiement

Une fois déployé, vous devrez :

1. Créer un utilisateur admin :
```bash
railway run php artisan p:user:make
```

2. Créer une location :
```bash
railway run php artisan p:location:make
```

3. Configurer vos nodes (serveurs Wings)

## Notes importantes

- Le port est automatiquement défini par Railway via la variable `$PORT`
- Les migrations s'exécutent automatiquement au démarrage
- Les caches sont optimisés au démarrage
- PHP-FPM et Nginx sont configurés pour fonctionner ensemble

## Troubleshooting

Si le déploiement échoue :

1. Vérifier les logs Railway
2. Vérifier que toutes les variables d'environnement sont définies
3. Vérifier la connexion à la base de données
4. Vérifier la connexion à Redis

## Performance

Pour de meilleures performances :

- Utiliser Redis pour cache et sessions
- Activer les caches Laravel (déjà fait automatiquement)
- Configurer un CDN pour les assets statiques
- Utiliser un service email transactionnel (SendGrid, Mailgun, etc.)
