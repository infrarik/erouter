# eRouter - Planificateur Itinéraire Multi-Étape (ABRP + ORS + Gemini)
<img width="451" height="450" alt="erouterlogo" src="https://github.com/user-attachments/assets/753a028a-65bd-487b-ad6b-abe8e0b35838" />


eRouter est un outil en PHP permettant de concevoir des itinéraires routiers personnalisés étape par étape, d'ajuster dynamiquement la densité des points de passage géographiques à transmettre à **A Better Route Planner (ABRP)**, et d'obtenir des analyses d'optimisation ou de fractionnement des coûts de péage grâce à l'intelligence artificielle de **Google Gemini**.

---

## Fonctionnalités / Features

### 🇫🇷 Français
- **Planification Multi-Étape** : Ajoutez autant d'étapes intermédiaires que nécessaire entre votre point de départ et votre destination finale.
- **Gestion Segmentée des Péages** : Choisissez indépendamment pour chaque tronçon de route si vous préférez un tracé avec péage ou sans péage.
- **Échantillonnage Ajustable** : Un curseur permet de définir la densité des coordonnées GPS envoyées à ABRP (par exemple, 1 point tous les 20 km) afin de forcer ABRP à respecter scrupuleusement l'itinéraire calculé par OpenRouteService (ORS).
- **Lien Profond ABRP** : Génération instantanée d'un lien (`deeplink`) exportant l'intégralité des points de cheminement vers la plateforme ABRP.
- **Optimisation des Péages par IA** : Analyse automatique des sections à péage par Gemini (modèle `gemini-2.5-flash` doté de la recherche Web en direct) pour identifier les astuces de fractionnement de péage et estimer les économies réalisables.

### 🇬🇧 English
- **Multi-Stop Planning**: Add as many intermediate waypoints as needed between your origin and final destination.
- **Segmented Toll Management**: Individually select whether each specific route segment should include or avoid toll roads.
- **Adjustable Sampling Density**: A slider defines the density of GPS coordinates sent to ABRP (e.g., 1 point every 20 km), forcing ABRP to strictly follow the route calculated by OpenRouteService (ORS).
- **ABRP Deeplink**: Instantly generates a deep link exporting all sampled waypoints directly into ABRP.
- **AI Toll Optimization**: Automatic analysis of toll segments by Gemini (`gemini-2.5-flash` with live Google Search enabled) to provide split-trolling recommendations and estimate potential financial savings.

---

## Configuration des Clés API / API Key Setup

### 1. OpenRouteService (ORS)
*Nécessaire pour le calcul des itinéraires, le décodage géométrique et l'autocomplétion des adresses.*
1. Rendez-vous sur le site officiel : [openrouteservice.org/dev/#/signup](https://openrouteservice.org/dev/#/signup).
2. Créez un compte gratuit.
3. Allez dans l'onglet **Dashboard**, puis dans la section **Tokens**.
4. Créez un nouveau jeton (token) de type `Standard` et copiez-le dans l'interface d'eRouter.

### 2. Google Gemini
*Nécessaire pour l'analyse, l'estimation et l'optimisation intelligente des coûts de péage.*
1. Rendez-vous sur la plateforme Google AI Studio : [aistudio.google.com/apikey](https://aistudio.google.com/apikey).
2. Connectez-vous avec votre compte Google.
3. Cliquez sur **Create API Key** (Jeu gratuit disponible, aucune carte de crédit requise pour l'usage de base).
4. Copiez la clé générée et collez-la dans le champ correspondant sur eRouter.

---

## Installation / Installation

1. Déposez le fichier `index.php` et l'image du logo associé dans le répertoire de votre serveur Web (compatible PHP 7.4+ ou PHP 8.x).
2. Assurez-vous que le script possède les permissions d'écriture pour créer automatiquement un dossier sécurisé nommé `private/` en dehors ou à la racine de votre projet afin d'y stocker les fichiers `ors_key.txt` et `gemini_key.txt`.
3. Lancez la page dans votre navigateur, renseignez vos clés API lors de la première configuration, et commencez à planifier vos trajets !
