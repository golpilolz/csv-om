<?= "<?php\n" ?>

declare(strict_types=1);

namespace <?= $namespace; ?>;

<?= $use_statements; ?>

#[CsvOm\Entity(<?php if ($file_option): ?>file: '<?= $file_option ?>'<?php endif ?>)]
class <?= $class_name . "\n" ?>
{
<?php foreach ($fields as $field): ?>
<?php if ($field['id']): ?>
    #[CsvOm\Id]
<?php else: ?>
    #[CsvOm\Column]
<?php endif ?>
<?php if ($field['nullable']): ?>
    private ?<?= $field['phpType'] ?> $<?= $field['name'] ?> = null;
<?php else: ?>
    private <?= $field['phpType'] ?> $<?= $field['name'] ?>;
<?php endif ?>

<?php endforeach ?>
<?php foreach ($fields as $i => $field): ?>
<?php $type = ($field['nullable'] ? '?' : '') . $field['phpType'] ?>
    public function <?= 'bool' === $field['type'] ? 'is' : 'get' ?><?= $field['methodSuffix'] ?>(): <?= $type . "\n" ?>
    {
        return $this-><?= $field['name'] ?>;
    }

    public function set<?= $field['methodSuffix'] ?>(<?= $type ?> $<?= $field['name'] ?>): static
    {
        $this-><?= $field['name'] ?> = $<?= $field['name'] ?>;

        return $this;
    }
<?php if ($i < count($fields) - 1): ?>

<?php endif ?>
<?php endforeach ?>
}
