<?php

/**
 * Catalogue initial des fournisseurs d'accès à Internet (FAI).
 *
 * Chargé par App\Support\IspCatalog::sync() : les opérateurs absents sont
 * ajoutés, ceux qui existent déjà ne sont jamais modifiés (seul un logo
 * fourni manquant est complété). Les logos proviennent de Wikimedia Commons
 * (voir data/isp-logos/CREDITS.md) ; un administrateur peut les remplacer.
 *
 * Champs : slug, nom, pays (ISO 3166-1, null = international), site web,
 * espace client, assistance, assistance professionnels, fichier du logo.
 *
 * @return list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: string|null, 5: string|null, 6: string|null, 7: string|null}>
 */
return [
    // France : grand public et professionnels
    ['orange', 'Orange', 'FR', 'https://www.orange.fr', 'https://espace-client.orange.fr', '3900', '3901', 'orange.svg'],
    ['orange-business', 'Orange Business', 'FR', 'https://www.orange-business.com', null, null, null, 'orange-business.svg'],
    ['sosh', 'Sosh', 'FR', 'https://www.sosh.fr', null, null, null, 'sosh.png'],
    ['sfr', 'SFR', 'FR', 'https://www.sfr.fr', 'https://www.sfr.fr/mon-espace-client/', '1023', null, 'sfr.svg'],
    ['sfr-business', 'SFR Business', 'FR', 'https://www.sfrbusiness.fr', null, null, null, 'sfr-business.jpg'],
    ['red-by-sfr', 'RED by SFR', 'FR', 'https://www.red-by-sfr.fr', null, null, null, null],
    ['bouygues-telecom', 'Bouygues Telecom', 'FR', 'https://www.bouyguestelecom.fr', 'https://www.bouyguestelecom.fr/mon-compte', '1064', null, 'bouygues-telecom.svg'],
    ['bouygues-telecom-business', 'Bouygues Telecom Business', 'FR', null, null, null, null, null],
    ['free', 'Free', 'FR', 'https://www.free.fr', 'https://subscribe.free.fr/login/', '3244', null, 'free.png'],
    ['free-pro', 'Free Pro', 'FR', 'https://www.free.pro', null, null, null, null],
    ['ovhcloud', 'OVHcloud', 'FR', 'https://www.ovhcloud.com', null, null, null, 'ovhcloud.png'],
    ['nordnet', 'Nordnet', 'FR', 'https://www.nordnet.com', null, null, null, null],
    ['k-net', 'K-Net', 'FR', 'https://www.k-net.fr', null, null, null, null],
    ['coriolis-telecom', 'Coriolis Telecom', 'FR', 'https://www.coriolis.com', null, null, null, null],
    ['videofutur', 'Videofutur', 'FR', null, null, null, null, null],
    ['kosc-telecom', 'Kosc Telecom', 'FR', 'https://www.kosc-telecom.fr', null, null, null, null],
    ['sewan', 'Sewan', 'FR', 'https://www.sewan.fr', null, null, null, null],
    ['nerim', 'Nerim', 'FR', null, null, null, null, null],
    ['adista', 'Adista', 'FR', 'https://www.adista.fr', null, null, null, null],
    ['celeste', 'Celeste', 'FR', 'https://www.celeste.fr', null, null, null, null],
    ['ielo', 'Ielo', 'FR', 'https://www.ielo.net', null, null, null, null],
    ['alsatis', 'Alsatis', 'FR', 'https://www.alsatis.com', null, null, null, null],
    ['netalis', 'Netalis', 'FR', null, null, null, null, null],
    ['axione', 'Axione', 'FR', 'https://www.axione.fr', null, null, null, null],
    ['altitude-infra', 'Altitude Infra', 'FR', 'https://www.altitudeinfra.fr', null, null, null, 'altitude-infra.png'],
    ['covage', 'Covage', 'FR', null, null, null, null, null],
    ['hexanet', 'Hexanet', 'FR', 'https://www.hexanet.fr', null, null, null, null],
    ['wifirst', 'Wifirst', 'FR', null, null, null, null, null],
    ['paritel', 'Paritel', 'FR', 'https://www.paritel.fr', null, null, null, null],
    ['bretagne-telecom', 'Bretagne Télécom', 'FR', 'https://www.bretagnetelecom.com', null, null, null, null],

    // Outre-mer
    ['orange-caraibe', 'Orange Caraïbe', 'GP', null, null, null, null, 'orange.svg'],
    ['orange-reunion', 'Orange Réunion', 'RE', null, null, null, null, 'orange.svg'],
    ['sfr-caraibe', 'SFR Caraïbe', 'GP', null, null, null, null, null],
    ['digicel', 'Digicel', null, 'https://www.digicelgroup.com', null, null, null, 'digicel.png'],
    ['zeop', 'Zeop', 'RE', 'https://www.zeop.re', null, null, null, 'zeop.png'],
    ['canal-plus-telecom', 'Canal+ Telecom', 'RE', null, null, null, null, null],
    ['only', 'Only', 'RE', null, null, null, null, null],
    ['outremer-telecom', 'Outremer Telecom', 'RE', null, null, null, null, null],
    ['opt-nouvelle-caledonie', 'OPT Nouvelle-Calédonie', 'NC', 'https://www.opt.nc', null, null, null, null],
    ['vini', 'Vini', 'PF', 'https://www.vini.pf', null, null, null, null],

    // Satellite et opérateurs d'infrastructure internationaux
    ['starlink', 'Starlink', null, 'https://www.starlink.com', null, null, null, 'starlink.png'],
    ['eutelsat', 'Eutelsat (Konnect)', null, 'https://www.eutelsat.com', null, null, null, 'eutelsat.png'],
    ['colt', 'Colt', null, 'https://www.colt.net', null, null, null, null],
    ['zayo', 'Zayo', null, 'https://www.zayo.com', null, null, null, 'zayo.png'],
    ['cogent', 'Cogent', null, 'https://www.cogentco.com', null, null, null, 'cogent.png'],
    ['lumen', 'Lumen', null, 'https://www.lumen.com', null, null, null, 'lumen.png'],
    ['vodafone', 'Vodafone', null, 'https://www.vodafone.com', null, null, null, 'vodafone.png'],

    // Belgique, Suisse, Luxembourg
    ['proximus', 'Proximus', 'BE', 'https://www.proximus.be', null, null, null, 'proximus.png'],
    ['voo', 'VOO', 'BE', 'https://www.voo.be', null, null, null, 'voo.png'],
    ['telenet', 'Telenet', 'BE', 'https://www.telenet.be', null, null, null, 'telenet.png'],
    ['orange-belgium', 'Orange Belgium', 'BE', 'https://www.orange.be', null, null, null, 'orange.svg'],
    ['swisscom', 'Swisscom', 'CH', 'https://www.swisscom.ch', null, null, null, 'swisscom.png'],
    ['sunrise', 'Sunrise', 'CH', 'https://www.sunrise.ch', null, null, null, 'sunrise.png'],
    ['salt', 'Salt', 'CH', 'https://www.salt.ch', null, null, null, 'salt.png'],
    ['post-luxembourg', 'POST Luxembourg', 'LU', 'https://www.post.lu', null, null, null, 'post-luxembourg.png'],

    // Reste de l'Europe
    ['telekom', 'Telekom (Deutsche Telekom)', 'DE', 'https://www.telekom.de', null, null, null, 'telekom.png'],
    ['o2', 'O2', 'DE', 'https://www.o2online.de', null, null, null, 'o2.png'],
    ['bt', 'BT', 'GB', 'https://www.bt.com', null, null, null, 'bt.png'],
    ['sky', 'Sky', 'GB', 'https://www.sky.com', null, null, null, 'sky.png'],
    ['virgin-media', 'Virgin Media', 'GB', 'https://www.virginmedia.com', null, null, null, 'virgin-media.png'],
    ['talktalk', 'TalkTalk', 'GB', 'https://www.talktalk.co.uk', null, null, null, 'talktalk.png'],
    ['telefonica', 'Telefónica', 'ES', 'https://www.telefonica.com', null, null, null, 'telefonica.png'],
    ['movistar', 'Movistar', 'ES', 'https://www.movistar.es', null, null, null, 'movistar.png'],
    ['iliad-italia', 'Iliad', 'IT', 'https://www.iliad.it', null, null, null, 'iliad.png'],
    ['tim', 'TIM', 'IT', 'https://www.tim.it', null, null, null, 'tim.png'],
    ['fastweb', 'Fastweb', 'IT', 'https://www.fastweb.it', null, null, null, 'fastweb.png'],
    ['kpn', 'KPN', 'NL', 'https://www.kpn.com', null, null, null, null],
    ['ziggo', 'Ziggo', 'NL', 'https://www.ziggo.nl', null, null, null, 'ziggo.png'],
    ['telia', 'Telia', 'SE', 'https://www.telia.se', null, null, null, 'telia.png'],
    ['telenor', 'Telenor', 'NO', 'https://www.telenor.com', null, null, null, 'telenor.png'],

    // Amérique du Nord
    ['bell', 'Bell', 'CA', 'https://www.bell.ca', null, null, null, 'bell.png'],
    ['rogers', 'Rogers', 'CA', 'https://www.rogers.com', null, null, null, 'rogers.png'],
    ['telus', 'Telus', 'CA', 'https://www.telus.com', null, null, null, 'telus.png'],
    ['videotron', 'Vidéotron', 'CA', 'https://www.videotron.com', null, null, null, 'videotron.png'],
    ['att', 'AT&T', 'US', 'https://www.att.com', null, null, null, 'att.png'],
    ['verizon', 'Verizon', 'US', 'https://www.verizon.com', null, null, null, 'verizon.png'],
    ['xfinity', 'Xfinity (Comcast)', 'US', 'https://www.xfinity.com', null, null, null, 'xfinity.png'],
    ['spectrum', 'Spectrum (Charter)', 'US', 'https://www.spectrum.com', null, null, null, 'spectrum.png'],
    ['cox', 'Cox', 'US', 'https://www.cox.com', null, null, null, 'cox.png'],
    ['t-mobile', 'T-Mobile', 'US', 'https://www.t-mobile.com', null, null, null, 't-mobile.png'],

    // Afrique, Moyen-Orient, Asie, Océanie
    ['maroc-telecom', 'Maroc Telecom', 'MA', 'https://www.iam.ma', null, null, null, 'maroc-telecom.png'],
    ['inwi', 'inwi', 'MA', 'https://www.inwi.ma', null, null, null, 'inwi.png'],
    ['orange-tunisie', 'Orange Tunisie', 'TN', 'https://www.orange.tn', null, null, null, 'orange.svg'],
    ['algerie-telecom', 'Algérie Télécom', 'DZ', 'https://www.algerietelecom.dz', null, null, null, 'algerie-telecom.png'],
    ['djezzy', 'Djezzy', 'DZ', 'https://www.djezzy.dz', null, null, null, 'djezzy.png'],
    ['ooredoo', 'Ooredoo', 'QA', 'https://www.ooredoo.com', null, null, null, 'ooredoo.png'],
    ['eand', 'e& (Etisalat)', 'AE', 'https://www.eand.com', null, null, null, 'eand.png'],
    ['mtn', 'MTN', 'ZA', 'https://www.mtn.com', null, null, null, 'mtn.png'],
    ['airtel', 'Airtel', 'IN', 'https://www.airtel.in', null, null, null, 'airtel.png'],
    ['jio', 'Jio', 'IN', 'https://www.jio.com', null, null, null, 'jio.png'],
    ['ntt', 'NTT', 'JP', 'https://group.ntt', null, null, null, 'ntt.png'],
    ['telstra', 'Telstra', 'AU', 'https://www.telstra.com.au', null, null, null, 'telstra.png'],
    ['optus', 'Optus', 'AU', 'https://www.optus.com.au', null, null, null, 'optus.png'],
];
