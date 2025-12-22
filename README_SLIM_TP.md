# 🔄 Système de Backup et Restauration - ObsiLock

## 📋 Table des matières

1. [Présentation du projet](#présentation-du-projet)
2. [Architecture de l'application](#architecture-de-lapplication)
3. [Installation et prérequis](#installation-et-prérequis)
4. [Script de backup](#script-de-backup)
5. [Script de restauration](#script-de-restauration)
6. [Procédures de test](#procédures-de-test)
7. [Axes d'amélioration](#axes-damélioration)

---

## 🎯 Présentation du projet

**ObsiLock** est une API REST développée en PHP avec le framework Slim, permettant de gérer un coffre-fort numérique avec upload, stockage et gestion de fichiers.

Ce document décrit le système de **sauvegarde automatisée** et de **restauration** de l'application ObsiLock.

### 📦 Contenu du backup

Le système de backup sauvegarde :

- ✅ **Base de données MySQL** complète (structure + données)
- ✅ **Fichiers uploadés** par les utilisateurs
- ✅ **Configuration** (.env, docker-compose.yml)
- ✅ **Code source** (src/, public/, migrations/)
- ✅ **Métadonnées** (taille, date, checksum SHA256)

---

## 🏗️ Architecture de l'application

### Services Docker

L'application ObsiLock utilise 3 conteneurs Docker :

```
┌─────────────────────────────────────────┐
│         obsilock_phpmyadmin             │
│    (Interface de gestion MySQL)         │
│         Port: via Traefik               │
└─────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────┐
│           obsilock_api                  │
│      (API REST PHP + Slim)              │
│         Port: 8080                      │
└─────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────┐
│           obsilock_db                   │
│         (MySQL 8.0)                     │
│       Volume: obsilock_db_data          │
└─────────────────────────────────────────┘
```

### Base de données

- **Nom** : `coffre_fort`
- **Utilisateur** : `obsilock_user`
- **Tables** :
  - `files` : métadonnées des fichiers uploadés
  - `settings` : configuration (quota, etc.)

### Structure des dossiers

```
/home/iris/slam/ObsiLock/
├── backup.sh                # Script de sauvegarde
├── restore.sh               # Script de restauration
├── docker-compose.yml       # Configuration Docker
├── .env                     # Variables d'environnement
├── src/                     # Code source PHP
│   ├── Controller/
│   └── Model/
├── public/                  # Point d'entrée web
│   └── index.php
├── storage/                 # Stockage des fichiers
│   └── uploads/
└── vendor/                  # Dépendances Composer
```

---

## ⚙️ Installation et prérequis

### Prérequis système

- **OS** : Linux (testé sur Ubuntu/Debian)
- **Docker** : version 20.x ou supérieure
- **Docker Compose** : version 2.x ou supérieure
- **Bash** : version 4.x ou supérieure
- **Espace disque** : minimum 1 Go libre

### Vérification des prérequis

```bash
# Vérifier Docker
docker --version
docker compose version

# Vérifier Bash
bash --version

# Vérifier l'espace disque
df -h /home/iris/slam/ObsiLock
```

### Installation initiale

```bash
# Cloner le projet (si nécessaire)
cd /home/iris/slam
git clone [URL_DU_REPO] ObsiLock
cd ObsiLock

# Rendre les scripts exécutables
chmod +x backup.sh restore.sh

# Créer le dossier de backups
mkdir -p /home/mohamed/backup/slam/obsilock

# Démarrer l'application
docker compose up -d

# Vérifier que les services fonctionnent
docker compose ps
```

---

## 💾 Script de backup

### Localisation

- **Script** : `/home/iris/slam/ObsiLock/backup.sh`
- **Dossier de sauvegarde** : `/home/mohamed/backup/slam/obsilock/`

### Fonctionnalités

Le script `backup.sh` effectue les opérations suivantes :

1. ✅ **Vérification des prérequis** (Docker, conteneurs actifs)
2. ✅ **Backup de la base de données MySQL**
   - Dump complet de la base `coffre_fort`
   - Compression gzip
   - Vérification de l'intégrité
3. ✅ **Backup des fichiers uploadés**
   - Archivage du dossier `storage/uploads/`
   - Conservation des permissions
4. ✅ **Backup de la configuration**
   - Fichiers `.env` et `docker-compose.yml`
   - Code source complet (src/, public/)
   - Scripts de migration
5. ✅ **Création d'une archive finale**
   - Compression tar.gz
   - Génération du checksum SHA256
   - Horodatage dans le nom du fichier
6. ✅ **Rotation automatique** des anciens backups (> 7 jours)

### Utilisation

#### Backup manuel

```bash
cd /home/iris/slam/ObsiLock
./backup.sh
```

#### Sortie du script

```
╔═════════════════════════════════════════════╗
║     BACKUP OBSILOCK - 2024-12-22 19:30:00  ║
╚═════════════════════════════════════════════╝

[2024-12-22 19:30:00] Vérification des prérequis...
[2024-12-22 19:30:01] ✓ Prérequis validés

=== Backup de la base de données ===
[2024-12-22 19:30:02] Backup de la base coffre_fort...
[2024-12-22 19:30:03] ✓ Base de données sauvegardée (2.3M)

=== Backup des fichiers uploadés ===
[2024-12-22 19:30:04] ✓ Fichiers uploadés sauvegardés (15M)

=== Backup de la configuration ===
[2024-12-22 19:30:05] ✓ Configuration sauvegardée

=== Création de l'archive finale ===
[2024-12-22 19:30:10] ✓ Archive créée: obsilock_backup_20241222_193000.tar.gz (18M)
[2024-12-22 19:30:11] ✓ Checksum SHA256: a1b2c3d4...

╔═════════════════════════════════════════════╗
║        BACKUP TERMINÉ AVEC SUCCÈS           ║
╚═════════════════════════════════════════════╝
```

#### Automatisation avec cron

Pour automatiser les backups quotidiens à 3h du matin :

```bash
# Éditer le crontab
crontab -e

# Ajouter cette ligne
0 3 * * * cd /home/iris/slam/ObsiLock && ./backup.sh >> /home/mohamed/backup/slam/obsilock/cron.log 2>&1
```

#### Vérifier les backups créés

```bash
# Lister les backups
ls -lh /home/mohamed/backup/slam/obsilock/

# Afficher les 5 derniers backups
ls -lt /home/mohamed/backup/slam/obsilock/*.tar.gz | head -5

# Vérifier l'intégrité d'un backup
cd /home/mohamed/backup/slam/obsilock/
sha256sum -c obsilock_backup_20241222_193000.tar.gz.sha256
```

### Structure d'un backup

```
obsilock_backup_20241222_193000.tar.gz
└── obsilock_backup_20241222_193000/
    ├── database/
    │   └── coffre_fort.sql.gz         # Dump MySQL compressé
    ├── uploads/
    │   └── uploads.tar.gz              # Fichiers uploadés
    ├── config/
    │   ├── .env                        # Variables d'environnement
    │   ├── docker-compose.yml          # Configuration Docker
    │   ├── src.tar.gz                  # Code source
    │   ├── public.tar.gz               # Point d'entrée web
    │   └── migrations.tar.gz           # Scripts de migration
    └── metadata.txt                    # Informations sur le backup
```

---

## 🔄 Script de restauration

### Localisation

- **Script** : `/home/iris/slam/ObsiLock/restore.sh`

### Fonctionnalités

Le script `restore.sh` effectue les opérations suivantes :

1. ✅ **Listing des backups disponibles** (interface interactive)
2. ✅ **Sélection du backup** à restaurer
3. ✅ **Confirmation de sécurité** (évite les erreurs)
4. ✅ **Extraction de l'archive**
5. ✅ **Arrêt des services Docker**
6. ✅ **Restauration de la base de données**
   - Démarrage du conteneur MySQL
   - Import du dump SQL
   - Vérification de l'intégrité
7. ✅ **Restauration des fichiers uploadés**
8. ✅ **Restauration de la configuration**
9. ✅ **Redémarrage complet des services**
10. ✅ **Vérification finale** (API, logs)

### Utilisation

#### Restauration interactive

```bash
cd /home/iris/slam/ObsiLock
./restore.sh
```

#### Sortie du script

```
╔═══════════════════════════════════════════════════════════╗
║      RESTAURATION OBSILOCK - 2024-12-22 19:45:00         ║
╚═══════════════════════════════════════════════════════════╝

=== Backups disponibles ===

┌─────┬────────────────────────────────────┬──────────┬──────────────────────┐
│  #  │ Nom du backup                      │ Taille   │ Date                 │
├─────┼────────────────────────────────────┼──────────┼──────────────────────┤
│   1 │ obsilock_backup_20241222_193000... │    18M   │ 2024-12-22 19:30:00  │
│   2 │ obsilock_backup_20241221_030000... │    17M   │ 2024-12-21 03:00:00  │
│   3 │ obsilock_backup_20241220_030000... │    16M   │ 2024-12-20 03:00:00  │
└─────┴────────────────────────────────────┴──────────┴──────────────────────┘

Sélectionnez le numéro du backup à restaurer (1-3) ou 'q' pour quitter: 1

[2024-12-22 19:45:10] INFO: Backup sélectionné: obsilock_backup_20241222_193000.tar.gz

⚠️  ATTENTION ⚠️
⚠️  Cette opération va ÉCRASER les données actuelles d'ObsiLock !
⚠️  Backup à restaurer: obsilock_backup_20241222_193000.tar.gz

Êtes-vous sûr de vouloir continuer ? (tapez 'OUI' en majuscules): OUI

[2024-12-22 19:45:15] Confirmation reçue. Début de la restauration...

=== Extraction du backup ===
[2024-12-22 19:45:16] ✓ Backup extrait

=== Arrêt des services Docker ===
[2024-12-22 19:45:20] ✓ Services arrêtés

=== Restauration de la base de données ===
[2024-12-22 19:45:25] Démarrage du conteneur MySQL...
[2024-12-22 19:45:35] ✓ MySQL est prêt
[2024-12-22 19:45:40] ✓ Base de données restaurée

=== Restauration des fichiers uploadés ===
[2024-12-22 19:45:42] ✓ Fichiers uploadés restaurés

=== Restauration de la configuration ===
[2024-12-22 19:45:43] ✓ .env restauré
[2024-12-22 19:45:43] ✓ docker-compose.yml restauré
[2024-12-22 19:45:45] ✓ Code source restauré

=== Redémarrage des services ===
[2024-12-22 19:45:50] ✓ Services redémarrés

=== Vérification de la restauration ===
[2024-12-22 19:45:55] ✓ API accessible

╔═══════════════════════════════════════════════════════════╗
║            RESTAURATION TERMINÉE AVEC SUCCÈS              ║
╠═══════════════════════════════════════════════════════════╣
║  API: http://api.obsilock.iris.a3n.fr:8080               ║
║  Logs: docker logs obsilock_api --tail 50                ║
╚═══════════════════════════════════════════════════════════╝
```

### Vérification post-restauration

```bash
# Vérifier l'état des conteneurs
docker compose ps

# Vérifier les logs de l'API
docker logs obsilock_api --tail 50

# Tester l'API
curl http://localhost:8080/

# Vérifier la base de données
docker exec obsilock_db mysql -u obsilock_user -p coffre_fort -e "SHOW TABLES;"

# Vérifier les fichiers uploadés
ls -la storage/uploads/
```

---

## 🧪 Procédures de test

### Test 1 : Backup complet

**Objectif** : Vérifier que le backup fonctionne correctement

**Étapes** :

1. S'assurer que l'application fonctionne :
   ```bash
   cd /home/iris/slam/ObsiLock
   docker compose ps
   ```

2. Uploader des fichiers de test via l'API :
   ```bash
   # Créer un fichier de test
   echo "Test backup" > /tmp/test.txt
   
   # L'uploader via Postman ou curl
   curl -X POST http://localhost:8080/files \
     -F "file=@/tmp/test.txt"
   ```

3. Lancer le backup :
   ```bash
   ./backup.sh
   ```

4. Vérifier la création du backup :
   ```bash
   ls -lh /home/mohamed/backup/slam/obsilock/
   ```

5. Vérifier l'intégrité :
   ```bash
   cd /home/mohamed/backup/slam/obsilock/
   sha256sum -c obsilock_backup_*.tar.gz.sha256 | tail -1
   ```

**Résultat attendu** :
- ✅ Archive créée avec horodatage
- ✅ Fichier .sha256 présent
- ✅ Checksum validé
- ✅ Taille > 10 Mo

---

### Test 2 : Restauration complète

**Objectif** : Vérifier que la restauration fonctionne

**⚠️ IMPORTANT** : Ce test ÉCRASE les données. À faire sur un environnement de test !

**Étapes** :

1. Noter l'état actuel :
   ```bash
   # Nombre de fichiers dans la base
   docker exec obsilock_db mysql -u obsilock_user -pSDNENJI2329nfzehzenideza coffre_fort \
     -e "SELECT COUNT(*) FROM files;"
   
   # Nombre de fichiers uploadés
   ls storage/uploads/ | wc -l
   ```

2. Créer un fichier marqueur :
   ```bash
   echo "AVANT RESTAURATION" > storage/uploads/MARQUEUR.txt
   ```

3. Faire un backup :
   ```bash
   ./backup.sh
   ```

4. Modifier quelque chose (pour voir la restauration) :
   ```bash
   echo "APRÈS BACKUP" > storage/uploads/MARQUEUR.txt
   ```

5. Lancer la restauration :
   ```bash
   ./restore.sh
   # Sélectionner le backup le plus récent
   # Taper "OUI" pour confirmer
   ```

6. Vérifier la restauration :
   ```bash
   # Le marqueur doit avoir retrouvé son contenu original
   cat storage/uploads/MARQUEUR.txt
   # Doit afficher : "AVANT RESTAURATION"
   
   # L'API doit répondre
   curl http://localhost:8080/
   
   # Les conteneurs doivent être actifs
   docker compose ps
   ```

**Résultat attendu** :
- ✅ Fichier marqueur restauré à son état d'origine
- ✅ API fonctionnelle
- ✅ Base de données restaurée
- ✅ Tous les conteneurs actifs

---

### Test 3 : Rotation des backups

**Objectif** : Vérifier que les anciens backups sont supprimés

**Étapes** :

1. Créer des backups de test avec dates anciennes :
   ```bash
   cd /home/mohamed/backup/slam/obsilock/
   
   # Créer un fichier de test ancien (9 jours)
   touch -d "9 days ago" obsilock_backup_old.tar.gz
   ```

2. Lancer un nouveau backup :
   ```bash
   cd /home/iris/slam/ObsiLock
   ./backup.sh
   ```

3. Vérifier que l'ancien fichier a été supprimé :
   ```bash
   ls /home/mohamed/backup/slam/obsilock/ | grep "old"
   # Ne doit rien afficher
   ```

**Résultat attendu** :
- ✅ Fichiers > 7 jours supprimés automatiquement
- ✅ Backups récents conservés

---

### Test 4 : Backup avec conteneurs arrêtés

**Objectif** : Vérifier la gestion d'erreur

**Étapes** :

1. Arrêter les conteneurs :
   ```bash
   cd /home/iris/slam/ObsiLock
   docker compose down
   ```

2. Tenter un backup :
   ```bash
   ./backup.sh
   ```

**Résultat attendu** :
- ❌ Message d'erreur clair
- ❌ Script s'arrête proprement
- ❌ Pas de fichier corrompu créé

---

### Test 5 : Test de performance

**Objectif** : Mesurer le temps de backup/restauration

**Étapes** :

1. Mesurer le temps de backup :
   ```bash
   time ./backup.sh
   ```

2. Mesurer le temps de restauration :
   ```bash
   time ./restore.sh
   ```

**Résultat attendu** :
- ✅ Backup : < 30 secondes
- ✅ Restauration : < 60 secondes

---

## 📈 Axes d'amélioration

### 🔧 Améliorations techniques

#### 1. Backup incrémental
**Actuellement** : Backup complet à chaque fois
**Amélioration** : Implémenter des backups incrémentiels
```bash
# Backup complet le dimanche
# Backups incrémentaux les autres jours
# Gain d'espace : ~70%
```

#### 2. Compression différentielle
**Actuellement** : gzip (compression standard)
**Amélioration** : Utiliser zstd ou xz pour une meilleure compression
```bash
# Passer de gzip à zstd
tar -I zstd -cf backup.tar.zst ...
# Gain de compression : 20-30%
```

#### 3. Backup distant automatique
**Actuellement** : Backup local uniquement
**Amélioration** : Synchronisation automatique vers serveur distant
```bash
# Ajouter rsync vers serveur de backup
rsync -avz /home/mohamed/backup/ backup-server:/backups/
# Sécurité : Protection contre perte du serveur principal
```

#### 4. Chiffrement des backups
**Actuellement** : Fichiers en clair
**Amélioration** : Chiffrer les archives avec GPG
```bash
# Chiffrement avec clé publique
gpg --encrypt --recipient backup@obsilock.fr backup.tar.gz
# Sécurité : Protection des données sensibles
```

#### 5. Notifications par email
**Actuellement** : Logs locaux uniquement
**Amélioration** : Envoi d'email en cas de succès/échec
```bash
# Intégrer mailutils ou sendmail
echo "Backup réussi" | mail -s "Backup ObsiLock OK" admin@obsilock.fr
```

---

### 🎯 Améliorations fonctionnelles

#### 6. Interface web de gestion
**Amélioration** : Dashboard pour :
- Lister les backups disponibles
- Lancer backup/restauration via interface
- Visualiser les statistiques (taille, fréquence)
- Planifier les backups

#### 7. Rétention intelligente
**Actuellement** : Suppression simple après 7 jours
**Amélioration** : Stratégie de rétention complexe
```
- Garder tous les backups des 7 derniers jours
- Garder 1 backup par semaine pendant 1 mois
- Garder 1 backup par mois pendant 6 mois
- Garder 1 backup par an pendant 5 ans
```

#### 8. Backup sélectif
**Amélioration** : Permettre de choisir quoi sauvegarder
```bash
./backup.sh --database-only
./backup.sh --files-only
./backup.sh --config-only
```

#### 9. Restauration partielle
**Amélioration** : Restaurer uniquement certains éléments
```bash
./restore.sh --database-only
./restore.sh --restore-file 123
```

#### 10. Monitoring et alertes
**Amélioration** : Surveillance proactive
- Alerte si aucun backup depuis 48h
- Alerte si backup échoue
- Alerte si espace disque < 10%
- Dashboard Grafana avec métriques

---

### 🔐 Améliorations sécurité

#### 11. Vérification d'intégrité renforcée
**Amélioration** : Vérifier périodiquement les anciens backups
```bash
# Cron hebdomadaire de vérification
0 4 * * 0 /home/iris/slam/ObsiLock/verify-backups.sh
```

#### 12. Logs d'audit
**Amélioration** : Traçabilité complète
- Qui a lancé le backup/restauration
- Quand et depuis quelle machine
- Résultat de l'opération
- Export vers système de logs centralisé (syslog, ELK)

#### 13. Authentification pour restauration
**Amélioration** : Sécuriser la restauration
```bash
# Demander un mot de passe admin
# Envoi de code 2FA par email
# Validation par 2 personnes (4-eyes principle)
```

---

### 💡 Améliorations DevOps

#### 14. Conteneurisation des scripts
**Amélioration** : Scripts dans un conteneur dédié
```dockerfile
FROM alpine:latest
RUN apk add --no-cache bash mysql-client
COPY backup.sh /usr/local/bin/
CMD ["/usr/local/bin/backup.sh"]
```

#### 15. Intégration CI/CD
**Amélioration** : Tests automatisés des scripts
```yaml
# .gitlab-ci.yml
test-backup:
  script:
    - ./test-backup-script.sh
```

#### 16. Backup multi-sites
**Amélioration** : Gérer plusieurs instances ObsiLock
```bash
# Configuration centralisée
./backup.sh --site obsilock-prod
./backup.sh --site obsilock-preprod
./backup.sh --site obsilock-dev
```

---

## 📊 Métriques et statistiques

### Performances actuelles

| Métrique | Valeur |
|----------|--------|
| Temps de backup moyen | ~25 secondes |
| Temps de restauration moyen | ~45 secondes |
| Taille moyenne d'un backup | 15-20 Mo |
| Rétention | 7 jours |
| Compression | gzip (ratio ~4:1) |

### Recommandations de production

Pour un environnement de production, il est recommandé de :

1. ✅ Activer le backup automatique quotidien (3h du matin)
2. ✅ Mettre en place un backup distant (rsync ou cloud)
3. ✅ Chiffrer les backups avec GPG
4. ✅ Tester la restauration mensuellement
5. ✅ Augmenter la rétention à 30 jours minimum
6. ✅ Mettre en place des alertes de monitoring
7. ✅ Documenter la procédure de disaster recovery

---

## 📞 Support et maintenance

### En cas de problème

1. **Vérifier les logs** :
   ```bash
   tail -f /home/mohamed/backup/slam/obsilock/*.log
   ```

2. **Vérifier l'état Docker** :
   ```bash
   docker compose ps
   docker compose logs
   ```

3. **Vérifier l'espace disque** :
   ```bash
   df -h /home/mohamed/backup
   ```

4. **Tester manuellement** :
   ```bash
   bash -x backup.sh  # Mode debug
   ```

### Contacts

- **Responsable système** : Mohamed
- **Documentation** : Ce fichier README
- **Dépôt Git** : https://github.com/orgs/Mediaschool-IRIS-BTS-SISR-2025/

---

## 📄 Licence et crédits

- **Projet** : ObsiLock - Coffre-fort numérique
- **Framework** : Slim 4 + Medoo
- **Date** : Décembre 2024
- **Auteur** : Mohamed
- **École** : Mediaschool IRIS - BTS SISR 2025

---

## 📝 Changelog

### Version 1.0 (22/12/2024)
- ✅ Script de backup complet
- ✅ Script de restauration interactif
- ✅ Vérification d'intégrité (SHA256)
- ✅ Rotation automatique des backups
- ✅ Support Docker Compose
- ✅ Documentation complète

---

**Fin du document**

*Dernière mise à jour : 22 décembre 2024*