# os-cloud-backup : sauvegarde de la configuration OPNsense vers S3 ou SFTP

Le plugin ajoute deux fournisseurs à **Système > Configuration > Sauvegardes**,
à côté de Google Drive / Nextcloud : **S3** (AWS, MinIO, Wasabi, Scaleway, OVH…)
et **SFTP**. Il s'appuie sur l'API « backup providers » du cœur d'OPNsense, il n'y a
donc ni page ni service supplémentaire.

## Fonctionnement

- À chaque exécution, `/conf/config.xml` est envoyé sous le nom
  `config-<hôte>.<domaine>-AAAAMMJJHHMMSS.xml` (horodatage UTC), chiffré si un mot
  de passe de chiffrement est renseigné (même format que l'export chiffré d'OPNsense,
  restaurable depuis la même page).
- Puis la rétention supprime les anciennes copies **de ce pare-feu uniquement** :
  - « Number of backups to keep » : garde les N plus récentes ;
  - « Number of days to keep backups » : supprime celles de plus de N jours ;
  - les deux ensemble : une copie est supprimée dès qu'une des limites est dépassée ;
  - la copie la plus récente n'est jamais supprimée ; les autres fichiers du dossier
    ne sont jamais touchés.

## Planification

- Dès qu'un fournisseur est activé, OPNsense lance la sauvegarde distante
  automatiquement chaque nuit à 01:00 (délai aléatoire de 0 à 1 h).
- Pour une autre fréquence : **Système > Paramètres > Cron**, ajouter une tâche avec la
  commande **« Remote backup »** (toutes les heures, toutes les 6 h…).
- Le bouton **Setup/Test** de la page enregistre les réglages et fait une sauvegarde
  immédiate, en affichant les fichiers présents ou l'erreur.

## Réglages

**S3** : endpoint sans slash final (`https://s3.eu-west-3.amazonaws.com`,
`https://minio.exemple.fr:9000`), région (`us-east-1` convient à la plupart des
services compatibles), bucket existant, clé d'accès / secrète, dossier optionnel
(`opnsense/fw1`). Requêtes en « path style » signées AWS v4. Droits nécessaires :
`s3:PutObject`, `s3:ListBucket`, `s3:DeleteObject`.

**SFTP** : serveur, port, utilisateur, **clé privée OpenSSH sans phrase de passe**
(sa clé publique dans `~/.ssh/authorized_keys` côté serveur), dossier existant
optionnel (relatif au home, ou absolu). La clé d'hôte du serveur est acceptée à la
première connexion puis vérifiée (`/conf/sftp_backup_known_hosts`) ; si le serveur
change de clé, supprimer ce fichier.

Générer une clé dédiée sur le pare-feu :
`ssh-keygen -t ed25519 -N '' -f /root/backup_key` puis coller `/root/backup_key`
dans le champ et `/root/backup_key.pub` sur le serveur.

## Installation

Méthode propre (paquet) : copier ce dossier dans un clone de
`https://github.com/opnsense/plugins` sous `sysutils/cloud-backup`, puis
`make package` sur une machine de build OPNsense/FreeBSD, et installer le `.pkg`.

Méthode rapide (test) : sur le pare-feu,
`cp -R src/opnsense/* /usr/local/opnsense/` puis recharger la page Sauvegardes.

## Tests

`php tests/run.php` vérifie la rétention. Avec `s3 <endpoint> <ak> <sk>` ou
`sftp <hôte> <port> <user> <clé> <dossier>` il fait une vraie sauvegarde (classes
OPNsense simulées). Testé contre moto (signature S3 vérifiée) et OpenSSH sshd.

## Licence

MIT, voir [LICENSE](LICENSE).
