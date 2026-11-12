#!/usr/bin/env python3
"""Beispieldaten für eine DEV-Instanz von nc_nexus anlegen (über die OCS-API).

    python3 scripts/seed-demo.py [--url http://localhost:8092]

Fiktives Unternehmen: Brenner Maschinenbau GmbH, Mittelständler für Sondermaschinen und
Bearbeitungszentren (rund 450 Beschäftigte). Bereiche Technik (Konstruktion, OT-Softwareentwicklung),
Produktion (Fertigung, Montage, Logistik), IT-Infrastruktur und Kaufmännischer Bereich (Finanzen,
Personal, Einkauf, Vertrieb). Anträge und Projekte in allen Stadien: Entwurf, Rückfrage, Analyse,
zurückgestellt, abgelehnt, Kandidat, im Portfolio, freigegeben, in Umsetzung, pausiert, abgebrochen,
abgeschlossen (mit Nachkalkulation) und geschlossen (mit Nutzenreview).

Voraussetzung: Die Demo-Konten existieren (scripts/seed-demo.sh legt sie per occ an) und die
App-Daten sind leer (./dev.sh reset-data oder frische Instanz).
"""
import argparse
import base64
import datetime
import json
import os
import sys
import urllib.error
import urllib.request

PASSWORD = os.environ.get('DEMO_PASSWORD', 'demo-Pass123!')
ADMIN = ('admin', os.environ.get('NX_ADMIN_PASSWORD', 'admin123'))
API = '/ocs/v2.php/apps/nc_nexus/api/v1'
YEAR = datetime.date.today().year


class Client:
    def __init__(self, base, user, password):
        self.base = base.rstrip('/')
        self.user = user
        token = base64.b64encode(f'{user}:{password}'.encode()).decode()
        self.headers = {
            'Authorization': f'Basic {token}',
            'OCS-APIREQUEST': 'true',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        }

    def call(self, method, path, data=None):
        body = None if data is None else json.dumps(data).encode()
        req = urllib.request.Request(self.base + API + path, data=body, method=method, headers=self.headers)
        try:
            with urllib.request.urlopen(req) as res:
                return json.loads(res.read())['ocs']['data']
        except urllib.error.HTTPError as e:
            sys.exit(f'{self.user}: {method} {path}: {e.code} {e.read().decode()[:400]}')

    def get(self, path):
        return self.call('GET', path)

    def post(self, path, data=None):
        return self.call('POST', path, data or {})

    def put(self, path, data):
        return self.call('PUT', path, data)


def month(offset=0):
    d = datetime.date.today().replace(day=1)
    m = d.month - 1 + offset
    return f'{d.year + m // 12}-{m % 12 + 1:02d}'


def day(offset):
    return (datetime.date.today() + datetime.timedelta(days=offset)).isoformat()


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--url', default='http://localhost:8092')
    args = parser.parse_args()

    admin = Client(args.url, *ADMIN)
    users = {u: Client(args.url, u, PASSWORD) for u in
             ['anna', 'ben', 'paula', 'boris', 'sven', 'fiona', 'kai', 'frank', 'mona', 'lars', 'olga', 'hanna']}
    paula = users['paula']

    if admin.get('/demands'):
        sys.exit('Es gibt bereits Anträge – zuerst ./dev.sh reset-data ausführen.')

    # ---- Organisation ------------------------------------------------------------------------
    print('Organisation und Rollen …')

    def unit(name, cc=None, parent=None):
        return admin.post('/org-units', {'name': name, 'costCenter': cc, 'parentId': parent})['id']

    org = {}
    org['GF'] = unit('Geschäftsführung', '1000')
    org['TEC'] = unit('Technik', '5000', org['GF'])
    org['KON'] = unit('Konstruktion', '5100', org['TEC'])
    org['OT'] = unit('OT-Softwareentwicklung', '5200', org['TEC'])
    org['PRO'] = unit('Produktion', '6000', org['GF'])
    org['FER'] = unit('Fertigung', '6100', org['PRO'])
    org['MON'] = unit('Montage', '6200', org['PRO'])
    org['LOG'] = unit('Logistik', '6300', org['PRO'])
    org['IT'] = unit('IT-Infrastruktur', '4100', org['GF'])
    org['KFM'] = unit('Kaufmännischer Bereich', '3000', org['GF'])
    org['FIN'] = unit('Finanzen und Controlling', '3100', org['KFM'])
    org['HR'] = unit('Personal', '3200', org['KFM'])
    org['EK'] = unit('Einkauf', '3300', org['KFM'])
    org['VT'] = unit('Vertrieb und Service', '2100', org['KFM'])

    for role, scope, user in [
        ('portfolio_manager', None, 'paula'),
        ('board_member', None, 'boris'),
        ('board_member', None, 'fiona'),
        ('pool_manager', org['IT'], 'sven'),
        ('pool_manager', org['OT'], 'olga'),
        ('pool_manager', org['KON'], 'kai'),
        ('sponsor', org['VT'], 'anna'),
        ('sponsor', org['PRO'], 'frank'),
        ('sponsor', org['TEC'], 'kai'),
    ]:
        admin.post('/role-assignments', {
            'role': role, 'scopeType': 'global' if scope is None else 'org_unit', 'scopeId': scope,
            'principalType': 'user', 'principalId': user,
        })

    # ---- Strategie ---------------------------------------------------------------------------
    print('Strategische Ziele …')

    def objective(code, title, parent=None, metric=None, owner=None):
        return admin.post('/objectives', {'code': code, 'title': title, 'parentId': parent,
                                          'targetMetric': metric, 'ownerUserId': owner})

    s1 = objective('S1', 'Smart Factory: Produktion vernetzen', owner='frank')
    s11 = objective('S1.1', 'Maschinendaten durchgängig erfassen', s1['id'], 'OEE-Transparenz an 100 % der Bearbeitungszentren')
    s12 = objective('S1.2', 'Durchlaufzeit in der Montage senken', s1['id'], '−20 % Durchlaufzeit bis 2028')
    s2 = objective('S2', 'Digitale Services für Kundenmaschinen', owner='anna')
    s21 = objective('S2.1', 'Fernwartung und Servicevertrag', s2['id'], '30 % Serviceumsatz mit digitalen Services')
    s3 = objective('S3', 'IT- und OT-Sicherheit (NIS-2, IEC 62443)', owner='sven')
    s4 = objective('S4', 'Effiziente Verwaltung und Engineering', owner='fiona',
                   metric='−10 % Verwaltungskosten je Auftrag')
    s41 = objective('S4.1', 'Durchgängiger Engineering-Prozess (PDM)', s4['id'], 'Konstruktionsänderungen < 5 Tage')

    # ---- Pools -------------------------------------------------------------------------------
    print('Ressourcenpools …')
    pools = {}
    for code, name, kind, fte, bottleneck, unit_id, manager, rate in [
        ('INF', 'IT-Infrastruktur', 'internal', 5, False, org['IT'], 'sven', 850),
        ('SEC', 'IT/OT-Security', 'internal', 2, True, org['IT'], 'sven', 980),
        ('SAP', 'SAP-Basis und ERP', 'internal', 2, True, org['IT'], 'sven', 1000),
        ('DEV', 'Softwareentwicklung Business-IT', 'internal', 3, False, org['IT'], 'sven', 820),
        ('OT', 'OT-Softwareentwicklung', 'internal', 6, True, org['OT'], 'olga', 900),
        ('KON', 'Konstruktion', 'business', 4, True, org['KON'], 'kai', 750),
        ('FER', 'Fachbereich Fertigung', 'business', 3, False, org['FER'], 'frank', 650),
        ('LOG', 'Fachbereich Logistik', 'business', 2, False, org['LOG'], 'lars', 600),
    ]:
        pool = admin.post('/pools', {'code': code, 'name': name, 'poolType': kind, 'isBottleneck': bottleneck,
                                     'orgUnitId': unit_id, 'managerUserId': manager, 'pdRate': rate})
        # ab 14 Monaten zurück gepflegt; spätere Monate übernehmen den Wert
        admin.put(f"/pools/{pool['id']}/capacity/{month(-14)}", {'fte': fte})
        pools[code] = pool['id']
    # Montage verstärkt im kommenden Halbjahr den OT-Pool nicht mehr – Engpass sichtbar machen
    admin.put(f"/pools/{pools['OT']}/capacity/{month(3)}", {'fte': 5})

    criteria = admin.get('/scoring/model')['set']['criteria']

    def scores(benefit, urgency, risk):
        values = {'benefit': benefit, 'urgency': urgency, 'risk': risk}
        return {c['code']: values[c['dimension']] for c in criteria}

    # ---- Bausteine ---------------------------------------------------------------------------
    def demand(owner, title, kind, problem, unit_key, needs=(), objectives=(), deadline=None, benefit=None, desc=None):
        client = users[owner]
        d = client.post('/demands', {'title': title, 'demandType': kind, 'problemStatement': problem,
                                     'orgUnitId': org[unit_key], 'deadline': deadline, 'expectedBenefit': benefit,
                                     'description': desc})
        if needs:
            client.put(f"/demands/{d['id']}/needs", {'needs': [
                {'poolId': pools[p], 'period': month(m), 'personDays': days} for p, m, days in needs]})
        if objectives:
            client.put(f"/demands/{d['id']}/objectives", {'objectiveIds': [o['id'] for o in objectives]})
        d['owner'] = owner
        return d

    def submit(d):
        users[d['owner']].post(f"/demands/{d['id']}/submit")

    def accept(d, cls, sc, effort, bucket, self_scores=None):
        submit(d)
        paula.post(f"/demands/{d['id']}/triage", {'code': 'accepted', 'projectClass': cls})
        if self_scores:
            users[d['owner']].post(f"/demands/{d['id']}/assessments", {'kind': 'self', 'scores': scores(*self_scores), 'effort': effort})
        paula.post(f"/demands/{d['id']}/assessments", {'kind': 'review', 'scores': scores(*sc), 'effort': effort,
                                                        'estimateQuality': 'plausible', 'bucket': bucket})

    def business_case(d, capex, annual, benefits, owner=None):
        users[owner or d['owner']].put(f"/demands/{d['id']}/business-case", {'content': {
            'costs': [{'label': 'Umsetzung (intern und extern)', 'kind': 'capex', 'amount': capex},
                      {'label': 'Betrieb, Wartung und Lizenzen', 'kind': 'opex_annual', 'amount': annual}],
            'benefits': benefits,
            'assumptions': [{'text': 'Fachbereich stellt Key User für Test und Abnahme', 'ownerUserId': d['owner'], 'checkDate': day(45)}],
            'discountRate': 6,
        }})

    def gate(d, step, outcome='approved', tranche=None, **extra):
        body = {'step': step, **extra}
        if step != 'candidate':
            body['outcome'] = outcome
        if tranche:
            body['tranche'] = {'amount': tranche, 'fiscalYear': YEAR}
        paula.post(f"/demands/{d['id']}/gate", body)

    def to_released(d, standard=True, budget=0):
        """Kandidat → G2 → G3. Standardpfad (M/L) mit Budgettranchen, verkürzter Pfad (XS/S) nur G3."""
        gate(d, 'candidate')
        gate(d, 'g2', tranche=round(budget * 0.1) if standard and budget else None)
        gate(d, 'g3', tranche=round(budget * 0.4) if budget else None)

    def project(d, manager, sponsor, committed=None):
        paula.put(f"/projects/{d['id']}", {'managerId': manager, 'sponsorId': sponsor, 'committed': committed})

    def actual(d, pool, m, pd):
        paula.post(f"/projects/{d['id']}/actuals", {'period': month(m), 'kind': 'effort', 'poolId': pools[pool], 'amount': pd})

    def cost(d, m, amount):
        paula.post(f"/projects/{d['id']}/actuals", {'period': month(m), 'kind': 'cost', 'amount': amount})

    def report(d, author, **fields):
        users[author].post(f"/projects/{d['id']}/reports", {'period': month(), **fields})

    def qual(title, metric, baseline, target, owner):
        return {'type': 'qualitative', 'title': title, 'metric': metric, 'baseline': baseline, 'target': target, 'ownerUserId': owner}

    def money(kind, title, amount, metric, baseline, target, owner):
        return {'type': kind, 'title': title, 'amount': amount, 'metric': metric, 'baseline': baseline, 'target': target, 'ownerUserId': owner}

    # ==== Abgeschlossene Projekte (Nachkalkulation, Nutzenreview) ==============================
    print('Abgeschlossene Projekte …')

    # A · Windows-Server 2016 ablösen (M, IT) – geschlossen nach G6 mit Nutzenreview
    srv = demand('ben', 'Windows-Server 2016 ablösen', 'lifecycle',
                 'Zwölf Server laufen noch mit Windows Server 2016; der erweiterte Support endet im Januar 2027.',
                 'IT', [('INF', -9, 20), ('INF', -8, 20), ('SEC', -8, 4)], [s3], deadline=day(60))
    accept(srv, 'M', (1, 8, 3), 3, 'A')
    business_case(srv, 38000, 6000, [
        money('avoided_cost', 'Kein Extended-Support-Vertrag', 24000, 'Supportkosten p. a.', '24 000 €', '0 €', 'ben'),
        qual('Weniger Sicherheitslücken', 'offene kritische Schwachstellen', '17', '0', 'sven'),
    ])
    to_released(srv, budget=38000)
    gate(srv, 'g4', tranche=19000)
    project(srv, 'ben', 'boris', 36000)
    for pool, m, pd in [('INF', -9, 22), ('INF', -8, 26), ('SEC', -8, 5)]:
        actual(srv, pool, m, pd)
    cost(srv, -8, 31500)
    gate(srv, 'g5')

    # B · Barcode-Scanner im Wareneingang (S, Logistik) – abgeschlossen
    scan = demand('lars', 'Mobile Scanner im Wareneingang', 'efficiency',
                  'Lieferscheine werden abgetippt; Fehlbuchungen führen zu Fehlteilen in der Montage.',
                  'LOG', [('DEV', -7, 12), ('SAP', -7, 6), ('LOG', -6, 8)], [s12],
                  benefit='Buchung direkt am Tor, weniger Fehlteile.')
    accept(scan, 'S', (3, 3, 1), 1, 'C', self_scores=(5, 3, 1))
    business_case(scan, 22000, 3500, [
        money('capacity', 'Entlastung Wareneingang', 18000, 'Buchungszeit je Lieferschein', '6 min', '1 min', 'lars'),
    ])
    to_released(scan, standard=False, budget=22000)
    project(scan, 'lars', 'frank', 20000)
    for pool, m, pd in [('DEV', -7, 14), ('SAP', -7, 6), ('LOG', -6, 9)]:
        actual(scan, pool, m, pd)
    cost(scan, -6, 9800)
    gate(scan, 'g5')

    # C · OPC-UA-Gateway für Altmaschinen (M, OT) – abgeschlossen, deutlich unterschätzt
    opc = demand('olga', 'OPC-UA-Gateway für Altmaschinen', 'strategic',
                 'Elf Bearbeitungszentren ohne Netzwerkschnittstelle liefern keine Maschinendaten.',
                 'OT', [('OT', -6, 20), ('OT', -5, 20), ('FER', -5, 6), ('SEC', -5, 4)], [s11],
                 benefit='Maschinendaten aller Altmaschinen im Leitstand.')
    accept(opc, 'M', (5, 3, 3), 5, 'C')
    business_case(opc, 95000, 9000, [
        money('cost_saving', 'Weniger ungeplante Stillstände', 60000, 'Stillstandsstunden p. a.', '410 h', '250 h', 'frank'),
        qual('Transparenz in der Fertigung', 'Maschinen mit OEE-Daten', '23 von 34', '34 von 34', 'frank'),
    ])
    to_released(opc, budget=95000)
    gate(opc, 'g4', tranche=47500)
    project(opc, 'olga', 'frank', 90000)
    for pool, m, pd in [('OT', -6, 26), ('OT', -5, 31), ('FER', -5, 7), ('SEC', -5, 4)]:
        actual(opc, pool, m, pd)
    cost(opc, -5, 41000)
    gate(opc, 'g5', 'approved_with_conditions',
         conditions=[{'title': 'Betriebshandbuch an die Instandhaltung übergeben', 'ownerId': 'olga', 'dueDate': day(14)}])

    # D · Bewerbermanagement-System (S, HR) – abgeschlossen, leicht überschätzt
    hrm = demand('hanna', 'Bewerbermanagement-System', 'efficiency',
                 'Bewerbungen kommen per Mail und Post; Rückmeldungen dauern zu lange, Bewerber springen ab.',
                 'HR', [('DEV', -5, 15), ('INF', -5, 4)], [s4])
    accept(hrm, 'S', (3, 3, 1), 1, 'C')
    business_case(hrm, 18000, 7200, [
        qual('Schnellere Rückmeldung an Bewerber', 'Tage bis zur Eingangsbestätigung', '9', '1', 'hanna'),
    ])
    to_released(hrm, standard=False, budget=18000)
    project(hrm, 'hanna', 'fiona', 18000)
    for pool, m, pd in [('DEV', -5, 13), ('INF', -4, 4)]:
        actual(hrm, pool, m, pd)
    gate(hrm, 'g5')

    # Nutzenreview am Windows-Projekt und G6
    users['ben'].post(f"/demands/{srv['id']}/benefits/p1/reviews", {'review': {
        'reviewDate': day(-3), 'actualValue': '0 €', 'actualAmount': 24000}})
    users['sven'].post(f"/demands/{srv['id']}/benefits/p2/reviews", {'review': {
        'reviewDate': day(-3), 'actualValue': '2', 'achievementPct': 85,
        'explanation': 'Zwei Altanwendungen brauchen noch SMBv1, Abschaltung im nächsten Quartal.'}})
    gate(srv, 'g6', reason='Nutzen im Wesentlichen erreicht')

    # Nutzenstand der übrigen abgeschlossenen Projekte
    users['frank'].put(f"/demands/{opc['id']}/benefits/p1", {'tracking': {
        'realizationStatus': 'on_track', 'forecastAmount': 55000, 'forecast': '270 h', 'reviewDates': [day(-1), day(180)]}})
    users['lars'].put(f"/demands/{scan['id']}/benefits/p1", {'tracking': {
        'realizationStatus': 'at_risk', 'forecastAmount': 11000, 'forecast': '3 min', 'reviewDates': [day(20)]}})

    # ==== Laufende Projekte =====================================================================
    print('Laufende Projekte …')

    # E · MES-Einführung in der Fertigung (L) – in Umsetzung, gelb
    mes = demand('frank', 'MES-Einführung in der Fertigung', 'strategic',
                 'Fertigungsaufträge laufen auf Papier; Rückmeldungen erreichen das ERP erst am Folgetag.',
                 'FER', [('OT', -2, 25), ('OT', -1, 25), ('OT', 0, 30), ('OT', 1, 30), ('OT', 2, 20),
                         ('SAP', -1, 10), ('SAP', 0, 12), ('FER', 0, 15), ('FER', 1, 15), ('INF', 0, 8)],
                 [s11, s12], deadline=day(200),
                 benefit='Rückmeldung in Echtzeit, Durchlaufzeit sinkt, Nachkalkulation je Auftrag.')
    accept(mes, 'L', (8, 5, 5), 8, 'C', self_scores=(8, 8, 3))
    business_case(mes, 480000, 55000, [
        money('cost_saving', 'Weniger Rüst- und Wartezeiten', 160000, 'Durchlaufzeit Fertigungsauftrag', '11 Tage', '8 Tage', 'frank'),
        money('capacity', 'Wegfall der Papierrückmeldung', 70000, 'Stunden je Woche in der AV', '38 h', '8 h', 'frank'),
        qual('Termintreue gegenüber Kunden', 'Liefertermintreue', '81 %', '92 %', 'anna'),
    ])
    to_released(mes, budget=480000)
    gate(mes, 'g4', tranche=240000)
    project(mes, 'frank', 'boris', 210000)
    paula.post(f"/projects/{mes['id']}/milestones", {'name': 'Pilot an der Fräslinie 2', 'dueDate': day(-20), 'external': False})
    paula.post(f"/projects/{mes['id']}/milestones", {'name': 'ERP-Schnittstelle produktiv', 'dueDate': day(30), 'forecastDate': day(55), 'external': False})
    paula.post(f"/projects/{mes['id']}/milestones", {'name': 'Rollout alle Linien', 'dueDate': day(150), 'external': True})
    for pool, m, pd in [('OT', -2, 28), ('OT', -1, 31), ('SAP', -1, 14), ('OT', 0, 12)]:
        actual(mes, pool, m, pd)
    cost(mes, -2, 62000)
    cost(mes, -1, 48000)
    report(mes, 'frank', ragScope='green', ragTime='yellow', ragCost='yellow', ragRisk='yellow',
           ragComment='ERP-Schnittstelle verzögert sich um gut drei Wochen; SAP-Basis ist durch den Jahresabschluss gebunden.',
           progressPct=40, achievements='Pilot an Fräslinie 2 läuft stabil, 1 200 Rückmeldungen pro Tag.',
           nextSteps='Schnittstelle zu SAP PP testen, Schulung der Schichtführer.', forecastCost=515000, forecastEndDate=day(170))
    paula.post(f"/projects/{mes['id']}/risks", {'title': 'SAP-Basis ist im Jahresabschluss nicht verfügbar', 'category': 'resources',
                                                 'probability': 4, 'impact': 3, 'mitigation': 'Externe SAP-Beratung für vier Wochen beauftragen',
                                                 'residualProbability': 2, 'residualImpact': 3, 'ownerId': 'sven'})
    paula.post(f"/projects/{mes['id']}/risks", {'title': 'Akzeptanz der Schichtführer', 'category': 'organizational',
                                                 'probability': 3, 'impact': 4, 'mitigation': 'Key User je Schicht, Schulung am Arbeitsplatz',
                                                 'residualProbability': 2, 'residualImpact': 2, 'ownerId': 'frank'})
    paula.post(f"/projects/{mes['id']}/issues", {'title': 'Terminals an Linie 4 ohne WLAN-Abdeckung', 'severity': 'high', 'ownerId': 'ben', 'dueDate': day(7)})
    cr = paula.post(f"/projects/{mes['id']}/changes", {
        'title': 'Anbindung der Messmaschinen (CAQ) vorziehen', 'changeType': 'scope', 'deltaBudget': 38000, 'deltaDays': 21,
        'impactAnalysis': 'Drei Wochen mehr OT-Entwicklung; Qualitätsdaten stehen ab Rollout im MES.',
        'deltaRisk': 'mittel', 'deltaBenefit': 'Prüfprotokolle entfallen, Reklamationsbearbeitung schneller'})
    paula.post(f"/projects/{mes['id']}/changes/{cr['id']}/submit")

    # F · Fernwartungsplattform für Kundenmaschinen (M, OT) – in Umsetzung, rot
    rem = demand('anna', 'Fernwartungsplattform für Kundenmaschinen', 'strategic',
                 'Servicetechniker reisen für einfache Störungen zum Kunden; Wettbewerber bieten Fernwartung an.',
                 'VT', [('OT', -1, 15), ('OT', 0, 20), ('OT', 1, 15), ('SEC', 0, 8), ('INF', 0, 6)],
                 [s21, s3], benefit='Störungen per Fernzugriff lösen, Servicevertrag mit Fernwartung verkaufen.')
    accept(rem, 'M', (8, 5, 5), 5, 'C', self_scores=(8, 8, 5))
    business_case(rem, 140000, 22000, [
        money('revenue', 'Serviceverträge mit Fernwartung', 120000, 'Anzahl Verträge', '0', '60', 'anna'),
        money('cost_saving', 'Weniger Serviceeinsätze vor Ort', 45000, 'Einsätze pro Jahr', '520', '380', 'anna'),
    ])
    to_released(rem, budget=140000)
    gate(rem, 'g4', tranche=70000)
    project(rem, 'olga', 'anna', 52000)
    paula.post(f"/projects/{rem['id']}/milestones", {'name': 'Sicherheitskonzept freigegeben', 'dueDate': day(-10), 'forecastDate': day(25), 'external': False})
    paula.post(f"/projects/{rem['id']}/milestones", {'name': 'Erste Kundenmaschine angebunden', 'dueDate': day(45), 'forecastDate': day(80), 'external': True})
    for pool, m, pd in [('OT', -1, 19), ('OT', 0, 9), ('SEC', 0, 6)]:
        actual(rem, pool, m, pd)
    cost(rem, -1, 21000)
    report(rem, 'olga', ragScope='yellow', ragTime='red', ragCost='yellow', ragRisk='red',
           ragComment='Das Sicherheitskonzept (IEC 62443-4-2) ist nicht freigegeben; ohne Freigabe keine Anbindung beim Kunden.',
           progressPct=20, achievements='Plattform im Testbetrieb, VPN-Gateway ausgewählt.',
           nextSteps='Pentest beauftragen, Freigabe durch IT/OT-Security.', forecastCost=168000, forecastEndDate=day(130))
    paula.post(f"/projects/{rem['id']}/risks", {'title': 'Kunden lehnen Fernzugriff aus Sicherheitsgründen ab', 'category': 'security',
                                                 'probability': 3, 'impact': 5, 'mitigation': 'Zertifizierung nach IEC 62443, Opt-in je Sitzung',
                                                 'residualProbability': 2, 'residualImpact': 4, 'ownerId': 'anna'})
    paula.post(f"/demands/{rem['id']}/conditions", {'kind': 'action', 'title': 'Pentest der Fernwartungs-Gateways beauftragen', 'ownerId': 'sven', 'dueDate': day(10)})

    # G · Serverraum: Klimatisierung und USV (S) – freigegeben, Start nächsten Monat
    usv = demand('ben', 'Serverraum: Klimatisierung und USV erneuern', 'lifecycle',
                 'Die USV ist 13 Jahre alt, die Klimaanlage fiel im Sommer zweimal aus.',
                 'IT', [('INF', 1, 10), ('INF', 2, 6)], [s3], deadline=day(120))
    accept(usv, 'S', (1, 8, 5), 1, 'A')
    business_case(usv, 65000, 2500, [qual('Ausfallsicherheit Rechenzentrum', 'ungeplante Ausfälle p. a.', '2', '0', 'ben')])
    to_released(usv, standard=False, budget=65000)
    project(usv, 'ben', 'fiona', 58000)

    # H · E-Rechnung (Pflicht ab 2027/2028) (M) – freigegeben mit blockierender Auflage
    erech = demand('fiona', 'E-Rechnung im Ein- und Ausgang (XRechnung/ZUGFeRD)', 'mandatory',
                   'Ab 2027 müssen wir E-Rechnungen ausstellen; Eingangsrechnungen kommen bereits als XML.',
                   'FIN', [('SAP', 1, 12), ('SAP', 2, 10), ('DEV', 1, 10)], [s4], deadline=day(230))
    accept(erech, 'M', (3, 8, 3), 3, 'A')
    business_case(erech, 52000, 8000, [
        money('cost_saving', 'Porto und Druck entfallen', 14000, 'Papierrechnungen pro Monat', '2 300', '200', 'fiona'),
    ])
    to_released(erech, budget=52000)
    project(erech, 'fiona', 'boris', 25000)
    paula.post(f"/demands/{erech['id']}/conditions", {'kind': 'condition', 'title': 'Verfahrensdokumentation (GoBD) abstimmen', 'ownerId': 'fiona', 'dueDate': day(25), 'blocking': True})

    # I · Lagerverwaltung modernisieren (M) – pausiert (Budgetstopp)
    lvs = demand('lars', 'Lagerverwaltung mit Lagerplatzoptimierung', 'efficiency',
                 'Die Eigenentwicklung kennt keine Lagerplätze; Kommissionierer suchen Teile.',
                 'LOG', [('DEV', 3, 20), ('SAP', 3, 6), ('LOG', 3, 10)], [s12])
    accept(lvs, 'M', (5, 3, 3), 5, 'C')
    business_case(lvs, 120000, 15000, [
        money('capacity', 'Kürzere Kommissionierwege', 50000, 'Kommissionierzeit je Auftrag', '42 min', '28 min', 'lars'),
    ])
    to_released(lvs, budget=120000)
    gate(lvs, 'g4', 'deferred', reason='Budgetstopp bis zur Hochrechnung Q1', resubmissionDate=day(70))

    # J · RFID-Werkzeugverfolgung (M) – abgebrochen
    rfid = demand('frank', 'RFID-Werkzeugverfolgung', 'innovation',
                  'Werkzeuge werden zwischen Maschinen getauscht; Standzeiten sind unbekannt.',
                  'FER', [('OT', 1, 15), ('FER', 1, 5)], [s11])
    accept(rfid, 'M', (3, 1, 5), 3, 'D')
    business_case(rfid, 70000, 6000, [qual('Standzeiten bekannt', 'Werkzeuge mit Historie', '0 %', '90 %', 'frank')])
    to_released(rfid, budget=70000)
    gate(rfid, 'g4', 'cancelled', reason='Der Werkzeughersteller bietet die Verfolgung ab 2027 im Werkzeugschrank an; eigene Lösung nicht mehr wirtschaftlich.')

    # ==== Portfolio und Kandidaten ==============================================================
    print('Portfolio und Kandidaten …')

    # K · PDM/PLM-Einführung (L) – Kandidat, liegt dem Portfolio-Board vor
    pdm = demand('kai', 'PDM/PLM-System für die Konstruktion', 'strategic',
                 'CAD-Daten liegen auf Netzlaufwerken; Stücklisten werden von Hand ins ERP übertragen, Änderungen gehen verloren.',
                 'KON', [('KON', 2, 30), ('KON', 3, 30), ('DEV', 2, 20), ('SAP', 3, 15), ('INF', 2, 10)], [s41],
                 benefit='Eine Datenquelle für Konstruktion und Fertigung, Änderungen nachvollziehbar.')
    accept(pdm, 'L', (8, 5, 5), 8, 'C', self_scores=(8, 8, 3))
    business_case(pdm, 390000, 48000, [
        money('cost_saving', 'Weniger Fehlteile durch falsche Stücklisten', 90000, 'Fehlteilkosten p. a.', '140 000 €', '50 000 €', 'kai'),
        money('capacity', 'Schnellere Konstruktionsänderung', 65000, 'Tage je Änderung', '12', '5', 'kai'),
    ])
    gate(pdm, 'candidate')

    # L · Fahrerlose Transportsysteme (M) – im Portfolio (G2), wartet auf Freigabe
    fts = demand('lars', 'Fahrerlose Transportsysteme zwischen Lager und Montage', 'efficiency',
                 'Staplerfahrten zwischen Lager und Montage binden drei Mitarbeiter pro Schicht.',
                 'LOG', [('OT', 3, 25), ('OT', 4, 20), ('LOG', 3, 10), ('SEC', 3, 4)], [s12])
    accept(fts, 'M', (5, 3, 5), 5, 'C')
    business_case(fts, 210000, 18000, [
        money('cost_saving', 'Staplerfahrten entfallen', 95000, 'Staplerstunden pro Woche', '120 h', '40 h', 'lars'),
    ])
    gate(fts, 'candidate')
    gate(fts, 'g2', tranche=20000, reason='Hohe Wertdichte; Start nach der MES-Einführung')

    # M · OT-Netzsegmentierung (Pflicht NIS-2) (M) – im Portfolio, Engpass SEC
    seg = demand('sven', 'OT-Netzsegmentierung nach IEC 62443', 'mandatory',
                 'Fertigungsnetz und Büronetz sind nicht getrennt; NIS-2 verlangt Schutzmaßnahmen für die Produktion.',
                 'IT', [('SEC', 1, 12), ('SEC', 2, 14), ('INF', 1, 15), ('OT', 2, 8)], [s3], deadline=day(180))
    accept(seg, 'M', (3, 8, 5), 5, 'A')
    business_case(seg, 160000, 12000, [qual('NIS-2-Konformität', 'offene Prüffeststellungen', '9', '0', 'sven')])
    gate(seg, 'candidate')
    gate(seg, 'g2', tranche=16000)

    # N · EU-Maschinenverordnung: CE-Dokumentation digital (M) – Kandidat
    ce = demand('kai', 'Digitale Betriebsanleitung nach EU-Maschinenverordnung', 'mandatory',
                'Ab Januar 2027 dürfen Betriebsanleitungen digital bereitgestellt werden; Redaktion und Versand sind heute Papier.',
                'KON', [('KON', 1, 10), ('DEV', 2, 12)], [s2, s4], deadline=day(60))
    accept(ce, 'M', (3, 8, 1), 3, 'A')
    business_case(ce, 45000, 5000, [
        money('cost_saving', 'Druck und Versand der Anleitungen', 22000, 'gedruckte Anleitungen p. a.', '900', '100', 'kai'),
    ])
    gate(ce, 'candidate')

    # O · Montage-Arbeitsplatz mit Werkerführung (M) – in Bewertung
    wf = demand('mona', 'Digitale Werkerführung an den Montageplätzen', 'efficiency',
                'Montageanweisungen liegen als PDF vor; Varianten werden verwechselt, Nacharbeit kostet Zeit.',
                'MON', [('OT', 4, 20), ('KON', 4, 8), ('DEV', 4, 10)], [s12])
    accept(wf, 'M', (5, 3, 3), 5, 'C', self_scores=(5, 5, 3))

    # ==== Frühe Phasen ==========================================================================
    print('Frühe Phasen …')

    # eingereicht, wartet auf Triage
    submit(demand('mona', 'Leitstand-Monitore in der Montage', 'efficiency',
                  'Der Montagestatus steht auf Whiteboards; Schichtwechsel verlieren Informationen.',
                  'MON', [('INF', 2, 5), ('OT', 2, 6)], [s12]))
    submit(demand('hanna', 'Zeiterfassung: Terminals und Software ablösen', 'lifecycle',
                  'Der Hersteller stellt den Support für die Terminals Ende 2027 ein.', 'HR', [('INF', 3, 6), ('DEV', 3, 8)], [s4]))

    # Rückfrage
    cad = demand('kai', 'CAD-Arbeitsplätze erneuern', 'lifecycle', 'Die CAD-Workstations sind sechs Jahre alt.', 'KON', [('INF', 2, 8)])
    submit(cad)
    paula.post(f"/demands/{cad['id']}/query", {'question': 'Wie viele Arbeitsplätze sind betroffen, und ist der Tausch im Leasingvertrag schon eingeplant?', 'due': day(10)})

    # weitere Analyse
    pm = demand('frank', 'Predictive Maintenance für die Bearbeitungszentren', 'innovation',
                'Spindelschäden kündigen sich in Vibrationsdaten an, die heute niemand auswertet.',
                'FER', [('OT', 5, 30), ('FER', 5, 8)], [s11])
    submit(pm)
    paula.post(f"/demands/{pm['id']}/triage", {'code': 'further_analysis', 'analysisOwner': 'olga', 'analysisDue': day(30),
                                                'reason': 'Datenbasis aus dem OPC-UA-Gateway abwarten und Machbarkeit prüfen'})
    users['olga'].post(f"/demands/{pm['id']}/comments", {'message': 'Die Daten aus dem Gateway reichen für drei Maschinen; ich werte zwei Monate aus.'})

    # zurückgestellt
    ki = demand('anna', 'KI-gestützte Angebotskalkulation', 'innovation',
                'Angebote für Sondermaschinen dauern drei Wochen; Kalkulationswissen steckt in wenigen Köpfen.',
                'VT', [('DEV', 6, 25), ('KON', 6, 10)], [s2])
    submit(ki)
    paula.post(f"/demands/{ki['id']}/triage", {'code': 'deferred', 'resubmissionDate': day(120),
                                                'reason': 'Erst nach dem PDM-System sinnvoll (Stücklisten als Datenbasis)'})

    # abgelehnt
    cloud = demand('kai', 'Eigene CAD-Cloud für Zulieferer', 'innovation',
                   'Zulieferer brauchen Zeichnungen; heute per Mail.', 'KON', [('INF', 2, 15)])
    submit(cloud)
    paula.post(f"/demands/{cloud['id']}/triage", {'code': 'rejected',
                                                   'reason': 'Wird mit dem PDM-System (Lieferantenportal) abgedeckt; keine Insellösung.'})

    # Entwürfe
    demand('hanna', 'Schichtplanung digitalisieren', 'efficiency', 'Schichtpläne entstehen in Excel und hängen am Schwarzen Brett.', 'HR')
    demand('mona', 'Drehmomentschrauber mit Protokollierung', 'efficiency', '', 'MON')

    # ==== Gremien und Sitzung ===================================================================
    print('Gremien, Entscheidungsrechte, Sitzung …')
    board = paula.post('/bodies', {'code': 'PB', 'name': 'Portfolio-Board', 'bodyType': 'portfolio_board', 'chairId': 'boris',
                                   'quorumMin': 3, 'requiredSeats': ['Finanzen'],
                                   'description': 'Quartalsweise: Aufnahme, Freigabe und Abbruch von Großvorhaben (Klasse L).'})
    for member in [{'userId': 'boris', 'seat': 'Geschäftsführung'}, {'userId': 'fiona', 'seat': 'Finanzen', 'deputyId': 'ben'},
                   {'userId': 'frank', 'seat': 'Produktion'}, {'userId': 'kai', 'seat': 'Technik'}, {'userId': 'sven', 'seat': 'IT'}]:
        paula.post(f"/bodies/{board['id']}/members", member)
    resources = paula.post('/bodies', {'code': 'RB', 'name': 'Ressourcen-Board', 'bodyType': 'resource_board', 'chairId': 'sven', 'quorumMin': 2,
                                       'description': 'Monatlich: Engpass-Pools (OT, Security, SAP, Konstruktion) verteilen.'})
    for member in [{'userId': 'sven', 'seat': 'IT'}, {'userId': 'olga', 'seat': 'OT'}, {'userId': 'kai', 'seat': 'Konstruktion'}]:
        paula.post(f"/bodies/{resources['id']}/members", member)
    for g in ['g2', 'g3', 'g4']:
        paula.post('/decision-rights', {'gateCode': g, 'projectClasses': ['L'], 'bodyId': board['id']})
    paula.post(f"/demands/{pdm['id']}/gate/submit", {'step': 'g2'})
    session = paula.post('/sessions', {'bodyId': board['id'], 'scheduledAt': day(9) + 'T09:00', 'durationMin': 120, 'location': 'Besprechungsraum Werk 1'})
    session = paula.post(f"/sessions/{session['id']}/agenda")
    paula.put(f"/agenda-items/{session['items'][0]['id']}", {'recommendation': 'Aufnehmen mit Auflage: Migrationskonzept für die CAD-Altdaten vor G3.'})
    paula.post(f"/sessions/{session['id']}/topics", {'title': 'Engpass OT-Softwareentwicklung im nächsten Halbjahr'})
    paula.post(f"/sessions/{session['id']}/status", {'status': 'agenda_final'})

    paula.post('/conflicts/detect')

    print('Fertig. Anmelden z. B. als paula (PMO), boris (Geschäftsführung), frank (Fertigung), olga (OT),'
          ' kai (Konstruktion) – Passwort siehe DEMO_PASSWORD.')


if __name__ == '__main__':
    main()
