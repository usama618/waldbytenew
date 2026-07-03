CREATE TABLE tt_content (
    hero_eyebrow varchar(255) DEFAULT '' NOT NULL,
    hero_gradient_word varchar(255) DEFAULT '' NOT NULL,
    hero_primary_label varchar(255) DEFAULT '' NOT NULL,
    hero_primary_link varchar(1024) DEFAULT '' NOT NULL,
    hero_secondary_label varchar(255) DEFAULT '' NOT NULL,
    hero_secondary_link varchar(1024) DEFAULT '' NOT NULL,
    hero_score_label varchar(255) DEFAULT '' NOT NULL,
    hero_score_value varchar(32) DEFAULT '' NOT NULL,
    hero_score_total varchar(32) DEFAULT '' NOT NULL,
    services_eyebrow varchar(255) DEFAULT '' NOT NULL,
    services_link_label varchar(255) DEFAULT '' NOT NULL,
    services_link varchar(1024) DEFAULT '' NOT NULL,
    process_eyebrow varchar(255) DEFAULT '' NOT NULL,
    testimonials_eyebrow varchar(255) DEFAULT '' NOT NULL,
    blog_list_page varchar(255) DEFAULT '' NOT NULL,
    cta_primary_label varchar(255) DEFAULT '' NOT NULL,
    cta_primary_link varchar(1024) DEFAULT '' NOT NULL,
    cta_secondary_label varchar(255) DEFAULT '' NOT NULL,
    cta_secondary_link varchar(1024) DEFAULT '' NOT NULL,
    footer_brand_name varchar(255) DEFAULT '' NOT NULL,
    footer_brand_text text,
    footer_address_line_1 varchar(255) DEFAULT '' NOT NULL,
    footer_address_line_2 varchar(255) DEFAULT '' NOT NULL,
    footer_email varchar(255) DEFAULT '' NOT NULL,
    footer_phone varchar(255) DEFAULT '' NOT NULL,
    footer_copyright varchar(255) DEFAULT '' NOT NULL,
    footer_social_dribbble varchar(1024) DEFAULT '' NOT NULL,
    footer_social_linkedin varchar(1024) DEFAULT '' NOT NULL,
    footer_social_x varchar(1024) DEFAULT '' NOT NULL,
    footer_social_instagram varchar(1024) DEFAULT '' NOT NULL,
    tx_sitepackage_partner_logos int(11) DEFAULT '0' NOT NULL,
    tx_sitepackage_trust_items int(11) DEFAULT '0' NOT NULL,
    tx_sitepackage_value_points int(11) DEFAULT '0' NOT NULL,
    tx_sitepackage_showcase_slides int(11) DEFAULT '0' NOT NULL,
    tx_sitepackage_service_items int(11) DEFAULT '0' NOT NULL,
    tx_sitepackage_process_steps int(11) DEFAULT '0' NOT NULL,
    tx_sitepackage_testimonial_items int(11) DEFAULT '0' NOT NULL,
    tx_sitepackage_technology_demos int(11) DEFAULT '0' NOT NULL,
    tx_sitepackage_project_cards int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE pages (
    blog_badge varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_sitepackage_partner_logo (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_partner_logos' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    image int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);

CREATE TABLE tx_sitepackage_trust_item (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_trust_items' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    icon varchar(32) DEFAULT 'check' NOT NULL,
    label varchar(255) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);

CREATE TABLE tx_sitepackage_showcase_slide (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_showcase_slides' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    eyebrow varchar(255) DEFAULT '' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    bodytext text,
    cta_label varchar(255) DEFAULT '' NOT NULL,
    cta_link varchar(1024) DEFAULT '' NOT NULL,
    badge_label varchar(255) DEFAULT '' NOT NULL,
    image int(11) unsigned DEFAULT '0' NOT NULL,
    tx_sitepackage_showcase_metrics int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);

CREATE TABLE tx_sitepackage_showcase_metric (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_showcase_metrics' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    value_text varchar(64) DEFAULT '' NOT NULL,
    label varchar(255) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);

CREATE TABLE tx_sitepackage_value_point (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_value_points' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    icon varchar(32) DEFAULT 'bolt' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    bodytext text,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);

CREATE TABLE tx_sitepackage_service_item (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_service_items' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    icon varchar(32) DEFAULT 'code' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    bodytext text,
    cta_label varchar(255) DEFAULT '' NOT NULL,
    cta_link varchar(1024) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);

CREATE TABLE tx_sitepackage_process_step (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_process_steps' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    step_number varchar(8) DEFAULT '' NOT NULL,
    icon varchar(32) DEFAULT 'spark' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    bodytext text,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);

CREATE TABLE tx_sitepackage_testimonial_item (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_testimonial_items' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    quote_text text,
    author_name varchar(255) DEFAULT '' NOT NULL,
    author_role varchar(255) DEFAULT '' NOT NULL,
    image int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);

CREATE TABLE tx_sitepackage_technology_demo (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_technology_demos' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    bodytext text,
    primary_label varchar(255) DEFAULT '' NOT NULL,
    primary_link varchar(1024) DEFAULT '' NOT NULL,
    secondary_label varchar(255) DEFAULT '' NOT NULL,
    secondary_link varchar(1024) DEFAULT '' NOT NULL,
    note_text varchar(255) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);

CREATE TABLE tx_sitepackage_project_card (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(64) DEFAULT 'tx_sitepackage_project_cards' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL,
    badge_label varchar(255) DEFAULT '' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    bodytext text,
    meta_primary varchar(255) DEFAULT '' NOT NULL,
    meta_secondary varchar(255) DEFAULT '' NOT NULL,
    cta_label varchar(255) DEFAULT '' NOT NULL,
    cta_link varchar(1024) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY parent_uid (
        uid_foreign,
        tablename,
        fieldname,
        sorting_foreign
    )
);
