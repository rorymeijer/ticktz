# Het register beheren

## Apparaattypen

Een apparaattype is een vorm: laptop, server, telefoon, licentie. Elk type heeft eigen velden en een eigen nummerprefix, zodat laptops `LAP-0001` krijgen en servers `SRV-0001` en het nummer al zegt wat het ding is.

Geef een type de velden die daadwerkelijk worden opgezocht. Einddatum garantie, kostenplaats, aantal licenties — de dingen die iemand nodig heeft tijdens het behandelen van een ticket. Een veld dat niemand leest is een veld dat iemand moet invullen.

## Importeren

**Beheer → Apparatuur → Importeren** neemt een CSV aan.

Er is een voorbeeldweergave vóór het vastleggen. Lees die: hij toont wat er aangemaakt wordt, wat er bijgewerkt wordt, en wat niet gekoppeld kon worden. Importeren is de snelste manier om een register te vullen en de snelste manier om het met onzin te vullen, en die voorbeeldweergave is het verschil.

Koppel op het inventarisnummer. Dat is het ene veld dat uniek hoort te zijn en op het ding zelf gedrukt staat.

## Het kloppend houden

Een register dat niemand corrigeert wordt een register dat niemand vertrouwt, en dan corrigeert niemand het meer. Dit is het faalpatroon van elke CMDB, en het is geen technisch patroon.

De twee velden die het gewicht dragen zijn **wie hem heeft** en **de status**. Al het andere wordt daardoorheen gelezen. Houd je maar twee dingen accuraat, houd dan die.

Twee gewoonten die helpen:

- **Agents werken het bij vanaf het ticket.** Een apparaat eraan hangen kost een moment en de houder bijwerken nog een; een register dat als bijproduct van het werk wordt onderhouden blijft dichter bij de waarheid dan een dat in een project wordt onderhouden.
- **Afvoeren in plaats van verwijderen.** Een afgevoerd apparaat behoudt zijn geschiedenis, en die geschiedenis vertelt je dat de vervanger de derde van dit jaar is.
