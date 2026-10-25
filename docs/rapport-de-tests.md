# Rapport de tests et de validation

Ce rapport présente les tests de Gestion OM, leurs résultats à la livraison de l'étape 7 (mise en production), et la recette à conduire par les contrôleurs avant la mise en service (§ 16, livrable 12).

## 1. Synthèse

| Vérification | Résultat |
|---|---|
| Tests automatisés (Pest, sur PostgreSQL 16) | **170 tests, 1 011 vérifications, tous réussis** (35 s) |
| Analyse statique PHP (Larastan, niveau 6) | aucune erreur |
| Style PHP (Pint) | conforme |
| Types TypeScript (`vue-tsc`) | aucune erreur |
| Compilation de l'interface (Vite) | réussie |
| Failles connues des dépendances (`composer audit`, `npm audit`) | aucune |
| Parcours dans le navigateur avec la politique de sécurité active | 15 écrans, aucune violation, aucune erreur JavaScript |
| Sauvegarde et restauration | sauvegarde, restauration d'essai automatique et restauration manuelle réussies |

Environnement : PHP 8.3.6, Laravel 13.38, PostgreSQL 16.15, Node 22.22, Pest 4.7, Larastan 3.13, Chromium 153 (Playwright). Les mêmes vérifications tournent dans l'intégration continue GitHub à chaque pull request.

## 2. Stratégie de test

- **Tests fonctionnels de l'API** (la quasi-totalité) : chaque test passe par les routes HTTP réelles, avec authentification, validation, base PostgreSQL et journal d'audit, comme le ferait l'interface. Chaque test part d'une base vierge (migrations, puis transaction annulée en fin de test).
- **Tests des services métier** : calcul du per diem, moteur de contrôle et ses 30 règles, recherche, tableau de bord, sauvegarde, appelés directement quand c'est plus lisible.
- **Test unitaire** : conventions de durée d'une mission.
- **Base réelle** : les tests tournent sur PostgreSQL et non sur SQLite, pour vérifier les contraintes, les requêtes et les sauvegardes sur le moteur de production.
- **Cas de référence du cahier des charges** : R HC3, 3 jours en zone Nord puis 2 jours ailleurs, **503 000 Ar**, vérifié par le calcul, par la saisie d'un OM et par le simulateur de l'API. L'OM de référence ne déclenche aucune des 30 règles.
- **Principes métier vérifiés explicitement** : un OM incohérent s'enregistre sans blocage ; le calcul ne remplace jamais le montant déclaré ; un tarif manquant n'est jamais compté pour zéro ; le contrôle ne modifie jamais l'OM.

## 3. Couverture par domaine du cahier des charges

| Domaine | Fichiers de test | Tests | Vérifications |
|---|---|---|---|
| Comptes, connexion et sécurité (§ 3, 15.1, 17.3) | `Auth/LoginTest`, `Auth/SecurityTest`, `MeTest`, `Users/ManageUsersTest`, `Users/DeactivateUserTest`, `CreateFirstAccountCommandTest` | 30 | 173 |
| Traçabilité (§ 15.3) | `AuditLogTest` (et une vérification du journal dans la plupart des autres tests) | 2 | 8 |
| Employés, affectations, lieux, référentiels, import (§ 4, 6, 7) | `Employees/EmployeeTest`, `Referentials/ReferentialTest`, `Imports/ImportTest` | 26 | 134 |
| Barèmes et per diem (§ 9, 10) | `PerDiem/PerDiemCalculatorTest`, `PerDiem/RateVersionTest` | 20 | 100 |
| Ordres de mission (§ 5, 8) | `MissionOrders/MissionOrderTest`, `Unit/MissionDurationTest` | 17 | 109 |
| Contrôle et anomalies (§ 13) | `Control/RulesTest`, `Control/ControlEngineTest`, `Control/AnomalyApiTest` | 49 | 206 |
| Recherche, tableau de bord, rapports (§ 14) | `Reports/MissionOrderSearchTest`, `Reports/DashboardTest`, `Reports/ExportTest`, `Reports/ReportTest` | 18 | 227 |
| Sauvegarde et exploitation (§ 15.7) | `Operations/BackupTest`, `Operations/InstallationCheckTest` | 8 | 54 |
| **Total** | 23 fichiers | **170** | **1 011** |

## 4. Exigences de qualité du § 17.5

| Exigence | Tests qui la couvrent |
|---|---|
| Calcul des indemnités selon la catégorie, les zones et les quantités | `PerDiemCalculatorTest` : cas de référence 503 000 Ar, forfait repas des catégories DG à Directeur, tarif manquant jamais compté nul, tarif explicitement nul accepté, consommation sans objet, quantité négative signalée sans être soustraite, catégorie ou barème absents. `RateVersionTest` : barème du cahier des charges complet, version en vigueur à la date de départ, version figée après usage. `MissionOrderTest` : calcul à l'enregistrement, à côté du déclaré, séparé par missionnaire. |
| Détection d'un montant d'indemnités différent du calcul | `RulesTest` PD-01 (sans modifier le déclaré), PD-02 à PD-05 ; `ExportTest` : un calcul incomplet reste vide dans l'export, jamais compté nul ; `DashboardTest` : seuls les calculs complets sont additionnés. |
| Conditions de validation d'un OM | `RulesTest` VAL-01 (validateur missionnaire), VAL-02 (validateur non habilité), VAL-03 (validation non traçable). |
| Incohérences de dates, de transports et d'affectations | `RulesTest` DAT-01 à DAT-04, TRA-01 à TRA-04, AFF-01 à AFF-04 ; `MissionDurationTest` ; `MissionOrderTest` : affectation des missionnaires d'un OM d'affectation, historique conservé après suppression ; `EmployeeTest` : une ligne d'historique à chaque changement d'affectation. |
| Champs obligatoires et références aux employés enregistrés | `RulesTest` EMP-01 à EMP-03, OM-01 à OM-05 ; `MissionOrderTest` : références inexistantes et doublons de saisie refusés, document presque vide accepté ; `EmployeeTest` : fiche incomplète acceptée, matricule unique. |
| Permissions et accès aux données | `LoginTest` : compte désactivé refusé, accès coupé en cours de session, API refusée sans session ; `ManageUsersTest`, `DeactivateUserTest` : un compte ne modifie que les comptes qu'il a créés ; `ExportTest` : exports réservés aux contrôleurs connectés ; `SecurityTest` : limites de tentatives, en-têtes, https. |
| Tests d'intégration et scénarios réels | Tous les tests fonctionnels passent par l'API et la base réelle ; `AnomalyApiTest` suit une anomalie de sa détection à sa clôture, et un contrôle par lot dans la file d'attente ; parcours dans le navigateur (§ 6). |

## 5. Contrôles de sécurité

| Contrôle | Méthode | Résultat |
|---|---|---|
| Tentatives de connexion | `SecurityTest`, `LoginTest` | 5 par minute pour un compte et une adresse, 20 par minute par adresse, 30 par heure par compte ; un autre compte ou une autre adresse ne sont pas bloqués ; délai d'attente renvoyé au navigateur. |
| Limites de l'API | `SecurityTest` | 20 exports ou PDF par minute ; le reste de l'application reste disponible. |
| En-têtes de sécurité | `SecurityTest` et requête `curl` sur le serveur | `Content-Security-Policy` sans `unsafe-inline`, `X-Frame-Options: DENY`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` ; HSTS sur les requêtes https ; `X-Powered-By` absent. |
| Visite de l'API sans session | `SecurityTest` | réponse 401, sans erreur serveur, même sans en-tête `Accept: application/json`. |
| CSRF | requête `curl` de connexion sans jeton | refusée (419). |
| Mots de passe | `ManageUsersTest`, `CreateFirstAccountCommandTest`, `AuditLogTest` | mot de passe faible refusé ; jamais recopié dans le journal. |
| Injection SQL, caractères spéciaux | `EmployeeTest` (recherche), revue des requêtes | un `%` cherché est traité comme du texte, pas comme un joker ; aucune requête construite par concaténation de données saisies. |
| Formules dans les exports | `ExportTest` | un texte commençant par « = » reste un texte. |
| Configuration de production | `InstallationCheckTest` | `om:verifier-installation` relève débogage actif, http, cookie non sécurisé, absence de compte ou de sauvegarde. |
| Dépendances | `composer audit`, `npm audit` le 25/10/2026 | aucune faille connue. |

## 6. Parcours dans le navigateur

L'interface compilée a été servie avec la politique de sécurité de production, et parcourue dans Chromium par un script Playwright : connexion, puis tableau de bord, liste des OM, fiche, modification et création d'un OM, liste et fiche d'anomalie, employés et fiche employé, référentiels, barèmes, import, comptes, mon compte, journal d'audit (filtre Sauvegardes), et téléchargement du rapport de contrôle PDF d'un OM.

Résultat : les 15 écrans s'affichent, le PDF se télécharge, **aucune violation de la politique de contenu ni erreur JavaScript**. Seule une réponse 401 attendue apparaît sur l'écran de connexion (vérification de session avant connexion).

Les captures du [guide d'utilisation](guide-utilisation.md) proviennent de ce parcours ; celles des étapes 5 et 6 (contrôle, tableau de bord, version mobile, exports) avaient été produites de la même façon.

## 7. Sauvegarde et restauration

| Essai | Résultat |
|---|---|
| Sauvegarde puis restauration d'essai automatique (`BackupTest`) | empreinte SHA-256 et nombre de lignes de chaque table identiques ; fichiers en `0600` ; base temporaire supprimée ; journal d'audit renseigné. |
| Fichier modifié après la sauvegarde | refusé : empreinte différente. |
| Manifeste qui ne correspond pas à la restauration | écart signalé table par table. |
| Outil `pg_dump` introuvable | échec signalé, aucun fichier partiel, vérification suivante en échec explicite. |
| Durée de conservation | sauvegardes anciennes supprimées, la plus récente toujours gardée. |
| Base de développement (31 tables, 264 lignes), trois essais | sauvegarde en 0,4 s, restauration d'essai et comparaison en 0,7 s. |
| **Restauration manuelle complète** selon [exploitation.md § 4.3](exploitation.md#43-restaurer-la-base) | Après la sauvegarde, la référence d'un OM a été modifiée et un service ajouté ; la restauration (`pg_restore --clean --single-transaction`) a rétabli la référence d'origine et retiré le service, en 0,25 s, sans erreur. |

## 8. Limites

- **Performance** : aucun test de charge. Le § 15.5 laisse les cibles à préciser selon les volumes attendus ; les listes sont paginées et les indicateurs calculés en base. Un essai sur un volume réaliste (plusieurs milliers d'OM) est recommandé pendant la recette.
- **https réel** : la redirection, le certificat et HSTS dépendent du serveur cible ; ils se vérifient à l'installation (`om:verifier-installation` et cadenas du navigateur).
- **Navigateurs** : parcours automatisé sur Chromium seulement. Firefox, Edge et Safari sont à vérifier pendant la recette.
- **Recette** : les tests vérifient le comportement attendu tel que compris du cahier des charges ; la validation par les contrôleurs, sur leurs propres documents, reste à faire (§ 9).

## 9. Recette par les contrôleurs

À dérouler sur l'installation de production (ou une copie), avec de vrais OM. Noter le résultat de chaque scénario ; tout écart devient une demande de correction.

| N° | Scénario | Résultat attendu | Résultat obtenu | OK / KO | Contrôleur, date |
|---|---|---|---|---|---|
| R1 | Se connecter, se tromper 6 fois de mot de passe, puis attendre | Blocage avec délai indiqué, puis connexion possible | | | |
| R2 | Créer un compte pour un collègue, qui se connecte et change son mot de passe | Le collègue accède à l'application ; le journal montre la création | | | |
| R3 | Importer le fichier des lieux puis celui des employés (simulation puis import) | Bilan conforme ; lignes rejetées expliquées | | | |
| R4 | Saisir un OM de mission réel à plusieurs missionnaires, tel que le document | OM enregistré ; per diem calculé à côté du déclaré pour chacun | | | |
| R5 | Saisir l'exemple du cahier des charges (R HC3, 3 jours Nord, 2 jours ailleurs) | Per diem calculé : 503 000 Ar | | | |
| R6 | Saisir un OM volontairement incohérent (retour avant départ, validateur missionnaire) | OM enregistré sans blocage ; anomalies DAT-01 et VAL-01 au contrôle | | | |
| R7 | Lancer le contrôle sur un OM, confirmer, écarter (avec motif), transmettre et clôturer des anomalies | États et historique corrects ; statut de contrôle de l'OM mis à jour | | | |
| R8 | Corriger l'OM puis relancer le contrôle | Anomalies proposées closes ; confirmées marquées « plus détectées » | | | |
| R9 | Signaler une anomalie à la main | Anomalie confirmée à votre nom | | | |
| R10 | Saisir un OM d'affectation | Nouvelle affectation et historique sur la fiche des missionnaires | | | |
| R11 | Créer une nouvelle version du barème, laisser un tarif vide, enregistrer un OM concerné | Calcul « incomplet » avec la raison ; tarif jamais compté nul | | | |
| R12 | Contrôler un lot d'OM d'une période | Lot terminé ; anomalies proposées sur les OM concernés | | | |
| R13 | Rechercher des OM avec plusieurs critères, exporter en Excel et éditer le récapitulatif PDF | Mêmes OM à l'écran, dans Excel et dans le PDF | | | |
| R14 | Éditer le rapport de contrôle PDF d'un OM | Montants déclarés et calculés, anomalies et commentaires | | | |
| R15 | Consulter le journal d'audit (OM, anomalies, sauvegardes) | Opérations du jour avec leur auteur ; une sauvegarde et une vérification par nuit | | | |
| R16 | Utiliser l'application sur Firefox, Edge et un téléphone | Affichage et fonctions identiques | | | |
| R17 | Exercice de restauration (administrateur, [exploitation.md § 4.5](exploitation.md#45-exercice-de-restauration)) | Application restaurée et utilisable | | | |

Décision de mise en service : ☐ acceptée ☐ acceptée avec réserves ☐ refusée — date et signature : ……………………
