# AstroPsy — Description complète de l'application

### Présentation générale

AstroPsy est une application web de gestion de bibliothèque pour astrophotographes. Son rôle principal est de centraliser, organiser et exploiter toutes les données produites au cours de sessions d'observation et de traitement d'images astronomiques : fichiers bruts, masters, images traitées, logs de guidage, documents de référence, et métadonnées astronomiques.

L'application est utilisée par un ou plusieurs astrophotographes qui partagent un NAS (serveur de stockage réseau) contenant leurs fichiers. Elle lit l'arborescence de ce NAS, importe automatiquement les fichiers, et construit une base de données structurée permettant de naviguer, analyser et exporter les données de chaque session.

L'interface est entièrement en français et en anglais (sélectable), sombre ou claire (sélectable), et conçue pour être utilisée depuis un ordinateur de bureau.

---

### Module 1 — Cibles (Targets)

Une **cible** est un objet du ciel profond que l'astrophotographe souhaite imager : galaxie, nébuleuse, amas d'étoiles, etc.

**Ce que l'application permet de faire :**

- **Liste des cibles** avec tri (par nom, nombre de sessions, date de dernière session) et filtre (masquer les cibles sans session). Ces préférences sont mémorisées entre les visites.
- **Fiche détaillée de chaque cible** avec : image de prévisualisation, coordonnées célestes (RA/Dec), constellation, magnitude visuelle, type d'objet (traduit en français), identifiants de catalogue (M31, NGC 224, etc.), liste des sessions associées, documents liés.
- **Importation des métadonnées depuis Telescopius™** : en saisissant un nom, l'application interroge l'API Telescopius pour récupérer les coordonnées, le type, l'image miniature, et l'URL de la page Telescopius.
- **Upload d'une image de prévisualisation** personnalisée (redimensionnée automatiquement à 800 px).
- **Recherche de nouvelles cibles** sur le NAS : scan du répertoire de stockage pour détecter des dossiers cibles non encore enregistrés, avec possibilité d'importer en lot.
- **Génération d'une séquence N.I.N.A.™** : export d'un fichier JSON de séquence pour le logiciel de capture N.I.N.A., prêt à l'emploi.
- **Graphique de position Today** : courbe d'altitude de la cible et de la Lune sur 24h (aujourd'hui), calculée en temps réel dans le navigateur avec la bibliothèque astronomy-engine, incluant les crépuscules colorés, la ligne de minuit, et un tooltip avec altitude, azimuth et séparation lunaire.
- **Bloc Conseils de shooting** : calcule dynamiquement pour l'observatoire favori les 3 meilleures nuits sur 30 jours (score ★ selon durée utile, phase lunaire, séparation), les 3 meilleurs mois sur 12 mois (barres de progression), et les filtres recommandés selon le type d'objet (narrowband Hα/OIII/SII pour nébuleuses à émission, LRGB pour galaxies, RGB pour amas, etc.).
- **Liens rapides** vers Telescopius™ et AstroBin™ pour la cible.

---

### Module 2 — Sessions d'observation

Une **session** représente une nuit (ou partie de nuit) d'acquisition pour une cible donnée. Elle correspond à un dossier sur le NAS.

**Ce que l'application permet de faire :**

- **Liste globale des sessions** avec tableau paginé et recherche.
- **Fiche détaillée de chaque session** avec vue d'ensemble et galerie de prévisualisations.
- **Vue d'ensemble** : informations générales (date, site, notes, observatoire, configuration matérielle, auteurs), statistiques d'exposition (nombre de lights, durée totale par filtre, DOF — darks/offsets/flats), résumé du guidage PHD2 (RMS total, nombre de frames).
- **Galerie des lights** : vignettes de tous les fichiers LIGHT de la session avec filtres, durées d'exposition, températures capteur. Clic sur une vignette → vue détaillée de l'exposition.
- **Masters et Exports** : vignettes des fichiers master (darks/flats empilés) et des images traitées exportées (JPEG, PNG, TIFF), avec accès aux métadonnées.
- **Guidage PHD2** : graphique interactif de l'erreur de guidage (RA en bleu, Dec en rouge, corrections en barres), analyse FFT pour diagnostiquer l'erreur périodique de la monture, fenêtre temporelle ajustable (tout / 5 / 15 / 30 min), filtre des dithers. Affichage des calibrations.
- **Logs** : liste des fichiers log (PHD2, N.I.N.A., PixInsight) parsés et rattachés.
- **Documents** : documents PDF ou texte rattachés à la session.
- **Scan / Rafraîchissement** : re-scan du dossier session sur le NAS pour ingérer les nouveaux fichiers ou supprimer les entrées obsolètes.
- **Statistiques AstroBin** : récupération automatique des stats de publication (vues, likes, téléchargements) depuis AstroBin™.
- **Association à un observatoire et à une configuration matérielle** (Setup).

---

### Module 3 — Exposures (fichiers bruts)

Chaque fichier brut (LIGHT, DARK, FLAT, BIAS) est ingéré et analysé individuellement.

**Formats supportés :** FITS (.fit/.fits), XISF (PixInsight), NEF (Nikon RAW), TIFF, JPEG/PNG.

**Ce que l'application permet de faire :**

- **Lecture automatique des en-têtes** : extraction de toutes les métadonnées FITS (gain, offset, température capteur, filtre, durée, binning, focale, coordonnées, météo injectée, etc.).
- **Vignette générée à la volée** avec cache (miniature 8 bits normalisée pour les FITS 16/32 bits scientifiques).
- **Viewer FITS interactif** (JS9) : ouverture du fichier FITS brut dans un viewer astronomique en navigateur avec contrôles de stretch, zoom, coordonnées.
- **Détail de chaque exposition** : tableau complet des en-têtes, vignette haute résolution, lien vers le viewer.
- **Déduplication par hash** : un fichier déjà ingéré n'est jamais réimporté.
- **Ingestion asynchrone** via file de messages pour ne pas bloquer l'interface lors des scans.

---

### Module 4 — Observatoires

Un **observatoire** est un lieu d'observation (jardin, montagne, observatoire partagé, etc.).

**Ce que l'application permet de faire :**

- **Liste et fiche** : nom, ville, coordonnées GPS (lat/lon), altitude d'horizon minimale locale (en degrés, utilisée pour les calculs de visibilité), lien live (stream), commentaires.
- **Observatoire favori** : un observatoire peut être marqué comme favori ; il sert alors de référence pour tous les calculs de visibilité et conseils de shooting sur les fiches cibles.
- **Association aux sessions et aux configurations** matérielles.
- **Édition en modal** directement depuis la liste.

---

### Module 5 — Configurations matérielles (Setups)

Un **setup** est un profil d'équipement complet (télescope + caméra + monture + filtres + accessoires).

**Ce que l'application permet de faire :**

- **Liste des setups** avec résumé.
- **Fiche détaillée** : nom, notes, logo, auteur associé, observatoire associé.
- **Composants (SetupParts)** : ajout de chaque pièce d'équipement individuellement — marque, modèle, type (télescope principal, caméra, monture, filtre, accessoire, logiciel, lunette de guidage, caméra de guidage), notes, lien URL, photos. Les photos sont uploadées, stockées et servies depuis le NAS.
- **Suppression d'images** de composants directement depuis le formulaire d'édition.
- **Association aux sessions** pour tracer quel équipement a été utilisé lors de quelle session.

---

### Module 6 — Auteurs

Un **auteur** est un astrophotographe (personne physique).

**Ce que l'application permet de faire :**

- **Liste et fiche** : nom, logo, lien AstroBin.
- **Synchronisation AstroBin™** : récupération automatique (toutes les 5 minutes via cron) du profil public AstroBin — statistiques globales, images publiées, top followers, etc.
- **Association aux sessions** (plusieurs auteurs peuvent collaborer sur une session).
- **Association aux observatoires** (les auteurs utilisent un ou plusieurs observatoires).

---

### Module 7 — Documents

Un **document** est un fichier texte, PDF, ou référence attachée à une cible ou une session.

**Ce que l'application permet de faire :**

- **Ajout, édition, suppression** de documents.
- **Téléchargement** du fichier associé.
- **Tags** : étiquettes libres pour classifier les documents.
- **Icône personnalisée** : choisie parmi les icônes disponibles.
- **Vue globale** de tous les documents avec filtre et recherche.

---

### Module 8 — Dashboard

La page d'accueil rassemble une vue synthétique de l'état du système.

**Ce que l'application permet de faire :**

- **Compteurs globaux** : nombre total de cibles, sessions, exposures, masters, exports.
- **Météo et prévisions** : affichage des prévisions météo (température, vent, nébulosité, humidité, seeing astronomique, transparence) pour l'observatoire sélectionné, issues de l'API open-meteo, sur plusieurs jours.
- **Sélection d'observatoire** pour le widget météo.
- **Accès rapide** aux dernières sessions et aux widgets alpaca.

---

### Module 9 — Contrôle télescope (Alpaca)

L'application intègre un serveur de contrôle d'équipement d'astronomie via le protocole ASCOM Alpaca.

**Ce que l'application permet de faire :**

- **SafetyMonitor** : surveillance des conditions météo locales en temps réel (safe/unsafe).
- **Switch** : contrôle de 3 états booléens (alimentations, dôme, etc.) et lecture de N jauges configurables (température, humidité locale, etc.).
- **Dashboard Alpaca** : widgets visuels affichant le statut des devices, avec boutons d'action (allumer/éteindre).
- **Route de statut API** retournant l'état JSON de tous les devices pour une intégration externe éventuelle.
- **Configuration** via fichier (labels, types, icônes des switchs et jauges).

---

### Module 10 — Scan et ingestion automatique

L'ingestion des données du NAS est le cœur opérationnel de l'application.

**Ce que l'application permet de faire :**

- **Scan manuel** depuis l'interface (bouton « Rafraîchir depuis le stockage » sur la cible ou la session).
- **Scan automatique** via commande planifiée par le scheduler cron interne.
- **Détection automatique** des nouveaux dossiers cibles et sessions, des nouveaux fichiers (FITS, NEF, XISF, TIFF, logs PHD2, N.I.N.A., PixInsight).
- **Parse des headers FITS** via le microservice Python (astropy).
- **Parse des logs PHD2** : extraction des calibrations et des sessions de guidage avec tous les points de données.
- **Déduplication** par hash SHA — un fichier physiquement déplacé ou renommé est reconnu.
- **Nettoyage** : suppression des entrées base de données dont le fichier n'existe plus sur le NAS.

---

### Intégrations externes

| Service | Usage |
|---------|-------|
| **Telescopius™** | Métadonnées cibles (coordonnées, type, image, URL) |
| **AstroBin™** | Profils auteurs, statistiques de publication, images |
| **Open-Meteo** | Prévisions météo et seeing astronomique |
| **N.I.N.A.™** | Export de séquences de capture |
| **ASCOM Alpaca** | Contrôle d'équipement local (dôme, alimentations, capteurs) |
| **astronomy-engine** | Calculs de position/visibilité (JS, côté client) |

---

### Ce que l'application ne fait pas encore (pistes identifiées)

- Pas de **planning de sessions** (calendrier des nuits disponibles à venir, créneaux optimaux).
- Pas de **comparaison entre setups** (quelle configuration donne les meilleurs résultats sur un objet).
- Pas de **suivi de progression** par cible (heures totales accumulées par filtre, objectif défini par l'utilisateur).
- Pas de **partage** ou de gestion multi-utilisateurs avec permissions.
- Pas d'**exportation vers AstroBin** (sens inverse : upload d'image, création de post).
- Pas de **catalogue de cibles à imager** (wishlist / to-do list d'objets).
- Pas de **statistiques croisées** avancées (corrélation seeing PHD2 / qualité images, comparaison entre nuits, etc.).
- Pas de **gestion du traitement** (suivi de l'état d'avancement du traitement PixInsight d'une session).
- Pas de **notifications** (alerte météo favorable, nouvelle session détectée sur le NAS).
- L'**Alpaca** ne pilote pas encore d'autres types de devices (focuser, rotateur, caméra, monture).
