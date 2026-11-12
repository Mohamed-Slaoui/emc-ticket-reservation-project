<?php

namespace App\Form\Admin;

use App\DTO\PerformanceFormData;
use App\Entity\SeatingLayout;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The performance form: a dated show, with its configuration and its sales
 * hours.
 */
class PerformanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('layout', EntityType::class, [
                'label' => 'Seating configuration',
                'class' => SeatingLayout::class,
                // The configuration belongs to a room: the room is what is
                // booked, so the administrator has to see it.
                'choice_label' => static fn (SeatingLayout $layout): string => sprintf(
                    '%s v%d — %s',
                    $layout->getName(),
                    $layout->getVersion(),
                    $layout->getRoom()->getName(),
                ),
                'placeholder' => 'Choose a configuration',
                'attr' => ['class' => 'form__control'],
                'constraints' => [new Assert\NotNull(message: 'The seating configuration is required.')],
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Starts at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'The date and the time of the show.',
                'attr' => ['class' => 'form__control'],
                'constraints' => [new Assert\NotNull(message: 'The date of the show is required.')],
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'Ends at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['class' => 'form__control'],
            ])
            ->add('salesOpenAt', DateTimeType::class, [
                'label' => 'The sales open at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'Before that moment, the performance is not on sale.',
                'attr' => ['class' => 'form__control'],
            ])
            ->add('salesCloseAt', DateTimeType::class, [
                'label' => 'The sales close at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'After that moment, the performance is not on sale any more.',
                'attr' => ['class' => 'form__control'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PerformanceFormData::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'admin_performance';
    }
}
