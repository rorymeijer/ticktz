# E-mail

Twee helften die apart worden ingesteld: wat Ticktz verstuurt, en wat het leest.

## Versturen

Stel de SMTP-server in waar Ticktz doorheen verstuurt, en het adres waarvandaan.

**Sjablonen** zijn wat eruit gaat: een ticket aangemaakt, een reactie geplaatst, een fiattering gevraagd, een SLA overschreden. Elk sjabloon is aanpasbaar en bevat plaatshouders voor de gegevens van het ticket zelf.

Twee dingen die aandacht lonen:

- **Het antwoordadres moet een postbus zijn die Ticktz leest.** Dat is wat ervoor zorgt dat het beantwoorden van een melding op het ticket terechtkomt en niet in het niets.
- **Stuur jezelf er één van elk** voordat je live gaat. Een sjabloon met een kapotte plaatshouder ziet er in de editor prima uit.

## Lezen

Een **e-mailkanaal** is een postbus die Ticktz uitleest. Post die daar binnenkomt wordt een ticket, en antwoorden op bestaande tickets worden reacties daarop.

Per kanaal stel je in in welke wachtrij en als welk verzoektype binnenkomende post landt, zodat één Ticktz `support@` en `facilities@` naar verschillende wachtrijen kan laten lopen.

**Automatisch aanmaken** bepaalt wat er gebeurt als er post komt van een adres zonder account. Aan, en er wordt een melderaccount aangemaakt zodat ze hun verzoek in het portaal kunnen volgen. Uit, en het ticket wordt zonder aangemaakt. Aan is meestal goed voor een interne desk; denk er goed over na bij een adres dat het publiek kan bereiken.

## Hoe een antwoord zijn ticket vindt

Via een markering die Ticktz in de verstuurde post zet, niet via de onderwerpregel. Daarom vindt een antwoord zijn ticket ook nog nadat iemand het onderwerp heeft aangepast, en daarom kan het doorsturen van een oude melding een nieuw bericht aan een oud ticket hangen.

De verwerking is **idempotent**: hetzelfde bericht dat twee keer binnenkomt maakt geen twee tickets. Dat telt zwaarder dan het klinkt, want een postbus die halverwege een ronde faalt wordt opnieuw geprobeerd.

## Als er geen post binnenkomt

Op volgorde:

1. **Nu ophalen**, op het kanaal. Dat meldt wat het vond.
2. Controleer of het kanaal aanstaat en de inloggegevens nog werken — een verlopen app-wachtwoord is het gebruikelijke antwoord.
3. Kijk of de berichten ongelezen in de postbus staan. Ticktz leest ongelezen post; iets anders dat die eerst als gelezen markeert haalt hem weg.
