-- Central auxiliary dictionary. Original mappings remain in langlinks and interlanguage_links.
CREATE TABLE /*_*/interlanguage_title_keys (
	itk_lang VARBINARY(35) NOT NULL,
	itk_title VARBINARY(255) NOT NULL,
	itk_key VARBINARY(64) NOT NULL,
	PRIMARY KEY (itk_lang, itk_title, itk_key),
	INDEX itk_lookup (itk_lang, itk_key, itk_title)
) /*$wgDBTableOptions*/;
