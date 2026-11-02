<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Maker;

use Golpilolz\CsvOm\Om\Mapping\Types;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Component\String\UnicodeString;

/**
 * make:csv-entity — generates a class mapped to a CSV file, and the file's header row when it does not exist yet.
 */
final class MakeCsvEntity extends AbstractMaker
{
    /** Column type => PHP type. */
    private const array PHP_TYPES = [
        Types::STRING => 'string',
        Types::INT => 'int',
        Types::FLOAT => 'float',
        Types::BOOL => 'bool',
        Types::DATETIME_IMMUTABLE => '\\DateTimeImmutable',
    ];

    private ?string $file = null;

    /** @var list<array{name: string, type: string, phpType: string, nullable: bool, id: bool, methodSuffix: string}> */
    private array $fields = [];

    public function __construct(
        private readonly string $directory,
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
    ) {
    }

    public static function getCommandName(): string
    {
        return 'make:csv-entity';
    }

    public static function getCommandDescription(): string
    {
        return 'Create a new class mapped to a CSV file (golpilolz/csv-om)';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument('name', InputArgument::OPTIONAL, 'The class name of the entity (e.g. <fg=yellow>Book</>)')
            ->setHelp(<<<'TXT'
The <info>%command.name%</info> command creates a class mapped to a CSV file with golpilolz/csv-om attributes.
It asks for the class name, the CSV file and the fields interactively:

    <info>php %command.full_name% Book</info>

An <info>id</info> (int) field is added when you don't define one.
If the CSV file doesn't exist yet it is created with the header row.
TXT);
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void
    {
        $shortName = (new UnicodeString(Str::getShortClassName((string) $input->getArgument('name'))))->toString();
        $defaultFile = (new UnicodeString($shortName))->snake()->toString() . '.csv';

        $this->file = $io->ask(
            'CSV file, relative to the configured directory',
            $defaultFile,
            function (?string $value): string {
                $value = (string) $value;
                $this->assertRelativePath($value);

                return $value;
            },
        );

        $io->writeln('');
        $io->writeln('Add the fields of the entity (an <info>id</info> int field is added if missing).');

        $this->fields = [];
        while (true) {
            $io->writeln('');
            $names = array_column($this->fields, 'name');
            if ([] !== $names) {
                $io->writeln('<info>Fields so far:</info> ' . implode(', ', $names));
            }

            $name = $io->ask(
                'New field name (press <return> to stop adding fields)',
                null,
                function (?string $value) use ($names): ?string {
                    if (null === $value || '' === $value) {
                        return null;
                    }
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
                        throw new \InvalidArgumentException(sprintf('"%s" is not a valid property name.', $value));
                    }
                    if (in_array($value, $names, true)) {
                        throw new \InvalidArgumentException(sprintf('The field "%s" already exists.', $value));
                    }

                    return $value;
                },
            );
            if (null === $name) {
                break;
            }

            $isId = 'id' === $name;
            $type = $io->choice('Field type', Types::ALL, $isId ? Types::INT : Types::STRING);
            $nullable = $isId ? false : $io->confirm('Can this field be null (empty cell)?', false);
            $this->fields[] = $this->field($name, $type, $nullable, $isId);
        }

        $this->fields = $this->withId($this->fields);
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $details = $generator->createClassNameDetails((string) $input->getArgument('name'), 'Entity\\');
        $fields = $this->fields;
        $defaultFile = (new UnicodeString($details->getShortName()))->snake()->toString() . '.csv';
        $csvFile = $this->file ?? $defaultFile;

        $generator->generateClass($details->getFullName(), __DIR__ . '/../Resources/skeleton/CsvEntity.tpl.php', [
            'use_statements' => "use Golpilolz\\CsvOm\\Om\\Mapping as CsvOm;\n",
            'file_option' => $csvFile !== $defaultFile ? $csvFile : null,
            'fields' => $fields,
        ]);
        $generator->writeChanges();

        $created = $this->createHeader($csvFile, array_column($fields, 'name'));

        $this->writeSuccessMessage($io);
        $io->text($created
            ? sprintf('Created <info>%s</info> with its header row.', $csvFile)
            : sprintf('<info>%s</info> already exists in the CSV directory: make sure its header matches the fields.', $csvFile));
    }

    /**
     * Puts the id first, adding an int "id" when none was defined.
     *
     * @param list<array{name: string, type: string, phpType: string, nullable: bool, id: bool, methodSuffix: string}> $fields
     * @return list<array{name: string, type: string, phpType: string, nullable: bool, id: bool, methodSuffix: string}>
     */
    private function withId(array $fields): array
    {
        $ids = array_values(array_filter($fields, static fn (array $f): bool => $f['id']));
        $others = array_values(array_filter($fields, static fn (array $f): bool => !$f['id']));

        return [$ids[0] ?? $this->field('id', Types::INT, false, true), ...$others];
    }

    /**
     * @return array{name: string, type: string, phpType: string, nullable: bool, id: bool, methodSuffix: string}
     */
    private function field(string $name, string $type, bool $nullable, bool $id): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'phpType' => self::PHP_TYPES[$type],
            'nullable' => $nullable,
            'id' => $id,
            'methodSuffix' => ucfirst($name),
        ];
    }

    private function assertRelativePath(string $file): void
    {
        if ('' === $file || str_starts_with($file, '/') || str_contains($file, '\\') || in_array('..', explode('/', $file), true)) {
            throw new RuntimeCommandException(sprintf('The file "%s" must be a path relative to the CSV directory, without "..".', $file));
        }
    }

    /**
     * @param list<string> $columns
     */
    private function createHeader(string $csvFile, array $columns): bool
    {
        $path = rtrim($this->directory, '/') . '/' . $csvFile;
        if (file_exists($path)) {
            return false;
        }
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0o777, true) && !is_dir(dirname($path))) {
            throw new RuntimeCommandException(sprintf('Unable to create the directory "%s".', dirname($path)));
        }

        $handle = fopen($path, 'wb');
        if (false === $handle) {
            throw new RuntimeCommandException(sprintf('Unable to write "%s".', $path));
        }
        // escape '' like CsvStorage: league/csv's backslash default would corrupt values
        fputcsv($handle, $columns, $this->delimiter, $this->enclosure, '');
        fclose($handle);

        return true;
    }
}
